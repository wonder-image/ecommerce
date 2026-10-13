<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Throwable;
use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountPagination;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;

/** Le pagine del core più quelle dell'ecommerce: metodi di pagamento, ordini, dettaglio dell'ordine, abbonamenti e coupon. */
class EcommerceAccountController extends AccountController
{
    /** L'errore di un'azione sugli abbonamenti, mostrato al GET che segue. */
    private const SUBSCRIPTION_ERROR = 'ecommerce_account_subscription_error';

    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'payment-methods' => $this->paymentMethods(),
            'orders' => $this->orders(),
            'orders.show' => $this->order((string) ($parameters['code'] ?? '')),
            'coupons' => $this->coupons(),
            'subscriptions' => $this->subscriptions(),
            'subscriptions.change' => $this->changeSubscription((string) ($parameters['code'] ?? '')),
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
            'orders' => array_map(static fn (array $order): array => [
                'number' => (string) ($order['order_number'] ?? ''),
                'date' => OrderSheet::date((string) ($order['ordered_at'] ?? '')),
                'total' => CartPresenter::money($order['total'] ?? 0, (string) (($order['currency'] ?? '') ?: 'EUR')),
                'href' => Route::url('account.orders.show', ['code' => (string) $order['code']]),
            ], $orders),
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
        $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
        $order = $code !== '' && $contactId > 0 ? Order::find(['code' => $code, 'stage' => 'order'], 1) : null;
        if (!is_array($order) || !isset($order['id']) || (int) ($order['customer_id'] ?? 0) !== $contactId) {
            $this->notFound();
        }

        $this->page(Ecommerce::viewPath('pages/account/order.php'), 'orders', [
            'title' => (string) __t('ecommerce.account.orders.order_title', ['number' => (string) $order['order_number']]),
            'seo_url' => Route::url('account.orders.show', ['code' => (string) $order['code']]),
            'order' => AccountOrder::present($order),
        ]);
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

    /** Cambia piano: subito, il nuovo prezzo dal rinnovo dopo (G10.13). Solo verso un prezzo in vendita. */
    protected function changeSubscription(string $code): never
    {
        [$row] = $this->ownedSubscription($code);
        $target = trim($this->text($_POST['price'] ?? ''));
        $price = PlanCatalog::find($target, static fn (string $c): string => '');
        $priceRow = $price !== null ? PlanPrice::find(['code' => $target, 'deleted' => 'false'], 1) : null;

        $this->subscriptionAction(static function () use ($row, $priceRow): void {
            if (!is_array($priceRow) || (int) ($priceRow['id'] ?? 0) <= 0) {
                throw UserError::make('subscription.price_unavailable');
            }

            OnlineSubscriptions::changePlan((int) $row['id'], (int) $priceRow['id'], 'api');
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
