<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Throwable;
use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountPagination;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionSelection;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Support\Documents\OrderProforma;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanPriceRules;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;

/** Le pagine del core più quelle dell'ecommerce: metodi di pagamento, ordini, dettaglio dell'ordine, abbonamenti e coupon. */
class EcommerceAccountController extends AccountController
{
    /** L'errore di un'azione sugli abbonamenti, mostrato al GET che segue. */
    private const SUBSCRIPTION_ERROR = 'ecommerce_account_subscription_error';

    /** Aggiungi servizio fra un passo e l'altro: valori, errore e anteprima, legati all'abbonamento. */
    private const ADD_STATE = 'ecommerce_account_subscription_add';

    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'payment-methods' => $this->paymentMethods(),
            'orders' => $this->orders(),
            'orders.show' => $this->order((string) ($parameters['code'] ?? '')),
            'orders.proforma' => $this->orderProforma((string) ($parameters['code'] ?? '')),
            'coupons' => $this->coupons(),
            'subscriptions' => $this->subscriptions(),
            'subscriptions.add' => $this->addService((string) ($parameters['code'] ?? '')),
            'subscriptions.add.preview' => $this->previewService((string) ($parameters['code'] ?? '')),
            'subscriptions.add.confirm' => $this->confirmService((string) ($parameters['code'] ?? '')),
            'subscriptions.item.remove' => $this->removeItem((string) ($parameters['code'] ?? ''), (int) ($parameters['item'] ?? 0)),
            'subscriptions.item.change' => $this->changeItem((string) ($parameters['code'] ?? ''), (int) ($parameters['item'] ?? 0)),
            'subscriptions.cancel' => $this->cancelSubscription((string) ($parameters['code'] ?? '')),
            'subscriptions.card' => $this->subscriptionCard((string) ($parameters['code'] ?? '')),
            default => parent::handle($action, $parameters),
        };
    }

    /** La pagina sta dentro «Dati personali», che resta la voce attiva del menu. */
    protected function paymentMethods(): void
    {
        $this->page(Ecommerce::viewPath('pages/account/payment-methods.php'), 'personal', [
            'title' => (string) __t('ecommerce.account.payment_methods.title'),
            'seo_url' => Route::url('account.payment-methods'),
            'enabled' => Ecommerce::config('account.payment_methods.enabled', false) === true,
        ]);
    }

    /** Gli ordini (stage order, in qualsiasi stato) della scheda del cliente, dal più recente, a dieci per pagina. */
    protected function orders(): void
    {
        $contact = $this->contact((int) $this->user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        $condition = ['stage' => 'order', 'customer_id' => $contactId];
        // Senza scheda non si cerca: customer_id 0 sono gli ordini degli ospiti.
        $total = $contactId > 0 ? (int) (self::rows(Order::find($condition, null, null, null, 'COUNT(*) AS total'))[0]['total'] ?? 0) : 0;
        $pagination = AccountPagination::make($total, AccountPagination::requested());
        // A pari data decide l'id: senza, le pagine potrebbero ripetere o saltare un ordine.
        $orders = $total > 0 ? self::rows(Order::find($condition, $pagination['limit'], 'ordered_at DESC, id', 'DESC')) : [];

        $this->page(Ecommerce::viewPath('pages/account/orders.php'), 'orders', [
            'title' => (string) __t('ecommerce.account.orders.title'),
            'seo_url' => Route::url('account.orders'),
            'errors' => $this->contactErrors($contact),
            'orders' => array_map(static fn (array $order): array => AccountOrder::row($order), $orders),
            'pagination' => $pagination,
            'base_url' => Route::url('account.orders'),
        ]);
    }

    /**
     * Un ordine del cliente. Lo cerca per codice con le stesse regole dell'elenco (`stage = order`, la scheda del cliente):
     * ordini di altri, carrelli, ordini degli ospiti e codici inesistenti danno tutti 404, e senza scheda non si cerca nulla.
     */
    protected function order(string $code): void
    {
        $order = $this->ownOrder($code);

        $this->page(Ecommerce::viewPath('pages/account/order.php'), 'orders', [
            'title' => (string) __t('ecommerce.account.orders.order_title', ['number' => (string) $order['order_number']]),
            'seo_url' => Route::url('account.orders.show', ['code' => (string) $order['code']]),
            'order' => AccountOrder::present($order),
        ]);
    }

    /**
     * Il proforma in PDF di un ordine del cliente, con le stesse regole del dettaglio: un ordine non suo, una bozza o
     * un annullato danno 404. Il PDF esce da qui, senza il layout della pagina.
     */
    protected function orderProforma(string $code): void
    {
        $order = $this->ownOrder($code);

        if (!class_exists(OrderProforma::class) || !OrderProforma::available($order)) {
            $this->notFound();
        }

        OrderProforma::send($order);
    }

    /**
     * L'ordine del cliente con quel codice (`stage = order`, la sua scheda); altrimenti 404. Senza scheda non si cerca.
     *
     * @return array<string, mixed>
     */
    private function ownOrder(string $code): array
    {
        $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
        $order = $code !== '' && $contactId > 0 ? Order::find(['code' => $code, 'stage' => 'order'], 1) : null;
        if (!is_array($order) || !isset($order['id']) || (int) ($order['customer_id'] ?? 0) !== $contactId) {
            $this->notFound();
        }

        return $order;
    }

    /**
     * I coupon riservati al cliente e in corso (anche con tutti gli usi spesi o validi solo in negozio), a dieci per pagina.
     * Con la funzionalità spenta la pagina non c'è.
     */
    protected function coupons(): void
    {
        if (!Gestionale::feature('coupons')) {
            $this->notFound();
        }

        $contact = $this->contact((int) $this->user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        $coupons = AccountCoupons::forCustomer($contactId, date('Y-m-d H:i:s'));
        $pagination = AccountPagination::make(count($coupons), AccountPagination::requested());

        $this->page(Ecommerce::viewPath('pages/account/coupons.php'), 'coupons', [
            'title' => (string) __t('ecommerce.account.coupons.title'),
            'seo_url' => Route::url('account.coupons'),
            'errors' => $this->contactErrors($contact),
            'coupons' => array_slice($coupons, $pagination['offset'], $pagination['per_page']),
            'pagination' => $pagination,
            'base_url' => Route::url('account.coupons'),
        ]);
    }

    /**
     * Gli abbonamenti del cliente (G10.14), senza i pagamenti mai completati.
     * Con `online_subscriptions` spenta la pagina non c'è.
     */
    protected function subscriptions(): void
    {
        $this->requireSubscriptions();
        $contact = $this->contact((int) $this->user()->id);
        $error = (string) ($_SESSION[self::SUBSCRIPTION_ERROR] ?? '');
        unset($_SESSION[self::SUBSCRIPTION_ERROR]);

        $this->page(Ecommerce::viewPath('pages/account/subscriptions.php'), 'subscriptions', [
            'title' => (string) __t('ecommerce.account.subscriptions.title'),
            'seo_url' => Route::url('account.subscriptions'),
            'errors' => [...$this->contactErrors($contact), ...($error !== '' ? [$error] : [])],
            'subscriptions' => AccountSubscriptions::forCustomer((int) ($contact['id'] ?? 0)),
            'plans_url' => Route::url('ecommerce.subscriptions.plans'),
        ]);
    }

    /**
     * Aggiungi servizio (G10b §7): prima la scelta del prezzo, poi quantità,
     * personalizzazioni e madre, poi la quota pro-rata da confermare.
     */
    protected function addService(string $code): void
    {
        $row = $this->editableSubscription($code, false);
        $candidates = AccountSubscriptions::candidates();
        $items = AccountSubscriptions::items((int) $row['id']);
        $planOf = AccountSubscriptions::planOf($items, $candidates);
        $options = AccountSubscriptionItems::addable($candidates, $items, $planOf, AccountSubscriptions::parentsOf());
        $state = $this->addState($code);
        $priceCode = trim($this->text($_GET['price'] ?? ($state['price'] ?? '')));
        $candidate = isset($options[$priceCode]) ? $this->candidate($candidates, $priceCode) : null;

        if ($candidate === null || (string) ($state['price'] ?? '') !== $priceCode) {
            $state = [];
        }

        $fields = null;
        $parentsMissing = false;

        if ($candidate !== null) {
            $parents = $this->parentOptions($items, $candidate['plan_id'], $planOf);
            $parentsMissing = AccountSubscriptions::parentsOf()($candidate['plan_id']) !== [] && $parents === [];
            $fields = SubscriptionsController::configureFields($candidate['plan_id'], [
                ...(is_array($state['values'] ?? null) ? $state['values'] : []),
                'field' => $state['field'] ?? null,
                'message' => (string) ($state['message'] ?? ''),
            ], $parents);

            if (($state['field'] ?? null) === 'parent' && $fields['parent'] !== null) {
                $fields['parent']->error((string) $state['message']);
            }
        }

        $message = (string) ($state['message'] ?? '');
        // L'errore di un campo sta sotto il campo; gli altri in cima alla pagina.
        $fieldError = $fields !== null && ((($state['field'] ?? null) === 'parent' && $fields['parent'] !== null) || is_int($state['field'] ?? null));
        $preview = is_array($state['preview'] ?? null) ? $state['preview'] : null;

        $this->page(Ecommerce::viewPath('pages/account/subscription-add.php'), 'subscriptions', [
            'title' => (string) __t('ecommerce.account.subscriptions.add_title'),
            'seo_url' => Route::url('account.subscriptions.add', ['code' => $code]),
            'errors' => $message !== '' && !$fieldError ? [$message] : [],
            'subscription' => AccountSubscriptions::present($row, $candidates),
            'options' => $options,
            'price' => $candidate !== null ? $priceCode : '',
            'price_label' => $candidate !== null ? $candidate['label'] : '',
            'fields' => $fields,
            'parents_missing' => $parentsMissing,
            'preview' => $preview,
            'pick_url' => Route::url('account.subscriptions.add', ['code' => $code]),
            'preview_url' => Route::url('account.subscriptions.add.preview', ['code' => $code]),
            'confirm_url' => Route::url('account.subscriptions.add.confirm', ['code' => $code]),
            'back_url' => Route::url('account.subscriptions'),
        ]);
    }

    /**
     * L'anteprima di Aggiungi servizio: la riga passa gli stessi controlli del
     * gestionale (madre, personalizzazioni, intervalli) e Stripe dà la quota.
     * Niente cambia sull'abbonamento finché il cliente non conferma.
     */
    protected function previewService(string $code): never
    {
        $row = $this->editableSubscription($code, true);
        $priceCode = trim($this->text($_POST['price'] ?? ''));
        $values = $this->addValues();
        $back = Route::url('account.subscriptions.add', ['code' => $code]).'?price='.rawurlencode($priceCode);

        try {
            $candidates = AccountSubscriptions::candidates();
            $items = AccountSubscriptions::items((int) $row['id']);
            $planOf = AccountSubscriptions::planOf($items, $candidates);
            $options = AccountSubscriptionItems::addable($candidates, $items, $planOf, AccountSubscriptions::parentsOf());
            $candidate = isset($options[$priceCode]) ? $this->candidate($candidates, $priceCode) : null;

            if ($candidate === null) {
                throw UserError::make('subscription.price_unavailable');
            }

            // Come nella pagina dei piani: la quantità resta fra 1 e il massimo.
            $quantity = max(1, min(SubscriptionSelection::MAX_QUANTITY, is_numeric($values['quantity']) ? (int) $values['quantity'] : 1));

            $parentId = 0;

            if (AccountSubscriptions::parentsOf()($candidate['plan_id']) !== []) {
                $parents = $this->parentOptions($items, $candidate['plan_id'], $planOf);

                if ($parents === []) {
                    $this->saveAddState($code, $priceCode, $values, null, (string) __t('ecommerce.account.subscriptions.add_parent_missing'));
                    $this->redirect($back);
                }

                $parentId = ctype_digit($values['parent']) ? (int) $values['parent'] : 0;

                if (!isset($parents[$parentId])) {
                    $this->saveAddState($code, $priceCode, $values, 'parent', UserError::make('subscription.parent_required', ['name' => $candidate['label']])->getMessage());
                    $this->redirect($back);
                }
            }

            $item = [
                'plan_price_id' => $candidate['price_id'],
                'quantity' => $quantity,
                'customization' => $this->customization($values['customization']),
            ];
            $line = SubscriptionItems::prepareLine($item, $row, (string) ($row['prices_include_tax'] ?? 'true') !== 'false');
            $terms = array_map(static function (array $current): int {
                $price = PlanPrice::findById((int) ($current['plan_price_id'] ?? 0));

                return max(1, (int) (is_array($price) ? ($price['term_months'] ?? 1) : 1));
            }, $items);
            PlanPriceRules::assertIntervals([...$terms, (int) $line['term']]);

            $trial = (string) $row['status'] === 'trialing';
            $quote = $trial ? ['amount' => 0.0, 'currency' => (string) (($row['currency'] ?? '') ?: 'EUR')] : OnlineSubscriptions::quote((int) $row['id'], $item);

            $this->saveAddState($code, $priceCode, $values, null, '', [
                'item' => [...$item, 'parent_item_id' => $parentId],
                'name' => $candidate['label'],
                'amount' => CartPresenter::money($quote['amount'], strtoupper((string) $quote['currency'])),
                'trial' => $trial,
            ]);
        } catch (UserError $error) {
            $field = $error->field();
            $this->saveAddState($code, $priceCode, $values, $field > 0 ? $field : null, $error->getMessage());
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.account.subscriptions.add');
            $this->saveAddState($code, $priceCode, $values, null, (string) __t('ecommerce.subscriptions.error'));
        }

        $this->redirect($back);
    }

    /** Conferma Aggiungi servizio: la riga entra subito, con la quota pro-rata. */
    protected function confirmService(string $code): never
    {
        $row = $this->editableSubscription($code, true);
        $state = $this->addState($code);
        $preview = is_array($state['preview'] ?? null) ? $state['preview'] : null;

        if ($preview === null || !is_array($preview['item'] ?? null)) {
            $this->redirect(Route::url('account.subscriptions.add', ['code' => $code]));
        }

        unset($_SESSION[self::ADD_STATE]);

        $this->subscriptionAction(static function () use ($row, $preview): void {
            SubscriptionItems::add((int) $row['id'], $preview['item'], 'prorate', 0, 'api');
        }, (string) __t('ecommerce.account.subscriptions.added', ['name' => (string) $preview['name']]));
    }

    /** Togli servizio: solo a fine periodo, con le figlie (G10b §7). */
    protected function removeItem(string $code, int $itemId): never
    {
        [$row] = $this->ownedSubscription($code);
        $items = AccountSubscriptions::items((int) $row['id']);

        if (!AccountSubscriptionItems::belongs($this->itemOf($items, $itemId), (int) $row['id'])) {
            $this->notFound();
        }

        if (!AccountSubscriptions::editable($row) || !AccountSubscriptionItems::removable($items, $itemId)) {
            $this->subscriptionError((string) __t('ecommerce.account.subscriptions.not_editable'));
        }

        $result = ['ends_at' => '', 'removed' => []];

        $this->subscriptionAction(static function () use ($itemId, &$result): void {
            $result = SubscriptionItems::remove($itemId, 'period_end', 0, 'api');
        }, static fn (): string => count($result['removed']) > 1
            ? (string) __t('ecommerce.account.subscriptions.removed_children', ['date' => OrderSheet::date((string) $result['ends_at']), 'count' => (string) (count($result['removed']) - 1)])
            : (string) __t('ecommerce.account.subscriptions.removed', ['date' => OrderSheet::date((string) $result['ends_at'])]));
    }

    /** Cambia servizio: subito, con il conguaglio pro-rata (G10b §7). */
    protected function changeItem(string $code, int $itemId): never
    {
        [$row] = $this->ownedSubscription($code);
        $items = AccountSubscriptions::items((int) $row['id']);
        $item = $this->itemOf($items, $itemId);

        if (!AccountSubscriptionItems::belongs($item, (int) $row['id'])) {
            $this->notFound();
        }

        $candidates = AccountSubscriptions::candidates();
        $targets = AccountSubscriptions::editable($row) && (string) $item['status'] === 'active'
            ? AccountSubscriptionItems::changeTargets($item, $items, $candidates, AccountSubscriptions::planOf($items, $candidates), AccountSubscriptions::parentsOf())
            : [];
        $priceCode = trim($this->text($_POST['price'] ?? ''));
        $target = isset($targets[$priceCode]) ? $this->candidate($candidates, $priceCode) : null;

        if ($targets === []) {
            $this->subscriptionError((string) __t('ecommerce.account.subscriptions.not_editable'));
        }

        $this->subscriptionAction(static function () use ($itemId, $target): void {
            if ($target === null) {
                throw UserError::make('subscription.price_unavailable');
            }

            SubscriptionItems::change($itemId, $target['price_id'], 'prorate', [], 0, 'api');
        }, (string) __t('ecommerce.account.subscriptions.changed'));
    }

    /** Disdici: resta attivo fino a fine periodo o a fine vincolo. */
    protected function cancelSubscription(string $code): never
    {
        [$row] = $this->ownedSubscription($code);
        $end = '';

        $this->subscriptionAction(static function () use ($row, &$end): void {
            $end = OnlineSubscriptions::cancel((int) $row['id'], 'api');
        }, static fn (): string => (string) __t('ecommerce.account.subscriptions.cancelled', ['date' => OrderSheet::date($end)]));
    }

    /** Aggiorna la carta: il portale di Stripe, che torna qui. */
    protected function subscriptionCard(string $code): never
    {
        [$row] = $this->ownedSubscription($code);

        try {
            $url = OnlineSubscriptions::portalUrl((int) $row['id'], SubscriptionsController::absolute(Route::url('account.subscriptions')));
        } catch (UserError $error) {
            $_SESSION[self::SUBSCRIPTION_ERROR] = $error->getMessage();
            $this->redirect(Route::url('account.subscriptions'));
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.account.subscriptions.card');
            $_SESSION[self::SUBSCRIPTION_ERROR] = (string) __t('ecommerce.subscriptions.error');
            $this->redirect(Route::url('account.subscriptions'));
        }

        $this->redirect($url !== '' ? $url : Route::url('account.subscriptions'));
    }

    /** @return array{0: array<string, mixed>} l'abbonamento del cliente, dopo POST e CSRF */
    private function ownedSubscription(string $code): array
    {
        $this->requireSubscriptions();

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->notFound();
        }

        $this->requireCsrf();
        $row = AccountSubscriptions::owned($code, (int) ($this->contact((int) $this->user()->id)['id'] ?? 0));

        return $row === null ? $this->notFound() : [$row];
    }

    /** Esegue l'azione e torna alla pagina, con l'avviso o l'errore. */
    private function subscriptionAction(callable $action, string|callable $notice): never
    {
        try {
            $action();
            $this->flash(is_callable($notice) ? $notice() : $notice);
        } catch (UserError $error) {
            $_SESSION[self::SUBSCRIPTION_ERROR] = $error->getMessage();
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.account.subscriptions');
            $_SESSION[self::SUBSCRIPTION_ERROR] = (string) __t('ecommerce.subscriptions.error');
        }

        $this->redirect(Route::url('account.subscriptions'));
    }

    /**
     * L'abbonamento del cliente, se i suoi servizi si toccano ancora; in POST
     * anche il CSRF. Altrimenti 404 o il ritorno alla pagina con l'errore.
     *
     * @return array<string, mixed>
     */
    private function editableSubscription(string $code, bool $post): array
    {
        if ($post) {
            [$row] = $this->ownedSubscription($code);
        } else {
            $this->requireSubscriptions();
            $row = AccountSubscriptions::owned($code, (int) ($this->contact((int) $this->user()->id)['id'] ?? 0));

            if ($row === null) {
                $this->notFound();
            }
        }

        if (!AccountSubscriptions::editable($row)) {
            $this->subscriptionError((string) __t('ecommerce.account.subscriptions.not_editable'));
        }

        return $row;
    }

    private function subscriptionError(string $message): never
    {
        $_SESSION[self::SUBSCRIPTION_ERROR] = $message;
        $this->redirect(Route::url('account.subscriptions'));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function itemOf(array $items, int $itemId): ?array
    {
        foreach ($items as $item) {
            if ($itemId > 0 && (int) ($item['id'] ?? 0) === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<array{code: string, price_id: int, plan_id: int, label: string}> $candidates
     * @return array{code: string, price_id: int, plan_id: int, label: string}|null
     */
    private function candidate(array $candidates, string $code): ?array
    {
        foreach ($candidates as $candidate) {
            if ($code !== '' && $candidate['code'] === $code) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Le righe dell'abbonamento sotto cui può stare il piano: id ⇒ etichetta.
     *
     * @param list<array<string, mixed>> $items
     * @param array<int, int> $planOf
     * @return array<int, string>
     */
    private function parentOptions(array $items, int $planId, array $planOf): array
    {
        $options = [];

        foreach (AccountSubscriptionItems::parents($items, AccountSubscriptions::parentsOf()($planId), $planOf) as $parent) {
            $options[(int) $parent['id']] = SubscriptionItems::label($parent);
        }

        return $options;
    }

    /** @return array{quantity: string, customization: array<string, string>, parent: string} i valori del form, come li ha scritti il cliente */
    private function addValues(): array
    {
        $customization = [];

        foreach (is_array($_POST['customization'] ?? null) ? $_POST['customization'] : [] as $id => $value) {
            $customization[(string) $id] = is_scalar($value) ? trim((string) $value) : '';
        }

        return [
            'quantity' => trim($this->text($_POST['quantity'] ?? '1')),
            'customization' => $customization,
            'parent' => trim($this->text($_POST['parent'] ?? '')),
        ];
    }

    /** @param array<string, string> $values @return array<int, string> solo le voci compilate, con l'id della personalizzazione */
    private function customization(array $values): array
    {
        $customization = [];

        foreach ($values as $id => $value) {
            if (ctype_digit((string) $id) && (int) $id > 0 && $value !== '') {
                $customization[(int) $id] = $value;
            }
        }

        return $customization;
    }

    /** @return array<string, mixed> lo stato di Aggiungi servizio per quell'abbonamento, vuoto se è di un altro */
    private function addState(string $code): array
    {
        $state = $_SESSION[self::ADD_STATE] ?? null;

        return is_array($state) && (string) ($state['code'] ?? '') === $code ? $state : [];
    }

    /**
     * @param array<string, mixed> $values
     * @param string|int|null $field `parent` o l'id della personalizzazione
     * @param array<string, mixed>|null $preview
     */
    private function saveAddState(string $code, string $price, array $values, string|int|null $field, string $message, ?array $preview = null): void
    {
        $_SESSION[self::ADD_STATE] = [
            'code' => $code,
            'price' => $price,
            'values' => $values,
            'field' => $field,
            'message' => $message,
            'preview' => $preview,
        ];
    }

    private function requireSubscriptions(): void
    {
        if (!Gestionale::feature('online_subscriptions')) {
            $this->notFound();
        }
    }

    /**
     * Le righe di un `find()`: una riga sola, una lista o niente diventano sempre una lista.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_is_list($found) ? array_values(array_filter($found, 'is_array')) : [$found];
    }
}
