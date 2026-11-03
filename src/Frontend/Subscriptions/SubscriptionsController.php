<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Http\Csrf;
use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSubscriptionStart;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSubscriptions;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanLinks;
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;
use Wonder\View\View;

/**
 * Gli abbonamenti sul sito (G10 §2.1 e §5, G10b §7): la pagina pubblica dei
 * piani, la selezione dei servizi (in sessione, non nel carrello degli
 * ordini), il riepilogo con fatturazione e codice sconto, la pagina di Stripe
 * e il ritorno, che aspetta la notifica di Stripe.
 *
 * Senza `online_subscriptions` nessuna di queste pagine esiste (404).
 *
 * Le quattro domande del frontend:
 * - **piani**: `INDEX,FOLLOW`, canonical assoluto senza parametri, `ItemList`
 *   di `Product` con gli importi visibili, breadcrumb Home › Abbonamenti,
 *   `view_item_list` e `select_item`;
 * - **servizio**, **riepilogo** e **conferma**: `NOINDEX,NOFOLLOW`, nessun
 *   dato strutturato, `$SEO->breadcrumb = []`; `add_to_cart` e
 *   `remove_from_cart` alla pagina dopo l'azione, `begin_checkout` sul
 *   riepilogo, `purchase` una volta sola alla conferma.
 */
final class SubscriptionsController
{
    /** Le conferme già mandate a GTM in questa sessione: `purchase` non si ripete. */
    public const TRACKED = 'ecommerce_subscription_purchases';

    /** Gli eventi di un POST che la pagina dopo il redirect manda a GTM. */
    public const EVENTS = 'ecommerce.subscription_events';

    /** Quantità, personalizzazioni e madre del servizio dopo un errore. */
    private const CONFIGURE_STATE = 'ecommerce_subscription_configure';

    /** Quanto la conferma si ricarica da sola aspettando la notifica di Stripe. */
    public const WAIT_SECONDS = 180;

    public static function handle(string $action, array $parameters = []): void
    {
        if (!Gestionale::feature('online_subscriptions')) {
            self::notFound();
        }

        match ($action) {
            'plans' => self::plans(),
            'configure' => self::configure((string) ($parameters['price'] ?? '')),
            'add' => self::add((string) ($parameters['price'] ?? '')),
            'summary' => self::summary(),
            'start' => self::start(),
            'remove' => self::remove(),
            'confirm' => self::confirm((string) ($_GET['abbonamento'] ?? '')),
            default => self::notFound(),
        };
    }

    private static function plans(): void
    {
        $plans = PlanCatalog::present(PlanCatalog::load(), self::configureUrl(...));
        $title = (string) __t('ecommerce.subscriptions.plans.title');
        $url = self::absolute(self::route('ecommerce.subscriptions.plans'));
        $breadcrumbs = [
            ['url' => '/', 'name' => (string) __t('ecommerce.catalog.breadcrumb_home')],
            ['url' => self::route('ecommerce.subscriptions.plans'), 'name' => $title],
        ];

        self::seo($title, (string) __t('ecommerce.subscriptions.plans.seo'), $url, 'INDEX,FOLLOW', $breadcrumbs);

        View::make(Ecommerce::viewPath('pages/subscriptions/plans.php'), [
            'plans' => $plans,
            'title' => $title,
            'page_url' => $url,
            'breadcrumbs' => $breadcrumbs,
            'include_tax' => PlanCatalog::pricesIncludeTax(),
            'currency' => PlanCatalog::CURRENCY,
            'events' => self::pullEvents(),
        ])->render();
    }

    /**
     * Un servizio prima di entrare nella selezione: quantità, personalizzazioni
     * del piano e, se il piano ne vuole una, la riga madre. Chi ha già il
     * massimo di abbonamenti va nell'area cliente, dove può aggiungere servizi.
     */
    private static function configure(string $code): void
    {
        $url = self::configureUrl($code);
        self::requireLogin($url);
        self::requireRoom($code);
        $found = PlanCatalog::find($code, self::configureUrl(...));

        if ($found === null) {
            self::notFound();
        }

        $planId = (int) $found['plan']['id'];
        $state = self::pullConfigureState($code);
        $catalog = CheckoutSubscriptions::catalog();
        $mothers = self::mothersOf($planId);
        $parents = self::eligibleParents($mothers, $catalog);
        $missing = [];

        if ($mothers !== [] && $parents === []) {
            foreach ($catalog as $entry) {
                if (in_array((int) $entry['plan']['id'], $mothers, true)) {
                    $missing[(int) $entry['plan']['id']] = (string) $entry['plan']['name'];
                }
            }
        }

        $title = (string) $found['price']['full_name'];
        self::seo($title, (string) __t('ecommerce.subscriptions.configure.seo'), self::absolute($url), 'NOINDEX,NOFOLLOW', []);

        View::make(Ecommerce::viewPath('pages/subscriptions/configure.php'), [
            'plan' => $found['plan'],
            'price' => $found['price'],
            'title' => $title,
            'fields' => self::configureFields($planId, $state, $parents),
            'needs_parent' => $mothers !== [],
            'missing_parents' => array_values($missing),
            'selected' => count(SubscriptionSelection::items()),
            'include_tax' => PlanCatalog::pricesIncludeTax(),
            'currency' => PlanCatalog::CURRENCY,
            'plans_url' => self::route('ecommerce.subscriptions.plans'),
            'summary_url' => self::route('ecommerce.checkout.index'),
            'action' => self::route('ecommerce.subscriptions.add', ['price' => $code]),
        ])->render();
    }

    /** Il POST del servizio: entra nella selezione solo se la selezione intera resta valida. */
    private static function add(string $code): never
    {
        self::requirePost();
        $back = self::configureUrl($code);
        $customization = [];

        foreach (is_array($_POST['customization'] ?? null) ? $_POST['customization'] : [] as $id => $value) {
            $customization[(string) $id] = is_scalar($value) ? trim((string) $value) : '';
        }

        $parent = (string) ($_POST['parent'] ?? '');
        $item = [
            'price_code' => $code,
            'quantity' => (string) ($_POST['quantity'] ?? '1'),
            'customization' => $customization,
            'parent' => ctype_digit($parent) ? (int) $parent : -1,
        ];
        self::verifyCsrf($back, static fn () => self::rememberConfigureState($code, $item));
        self::requireLogin($back);
        $found = PlanCatalog::find($code, self::configureUrl(...));

        if ($found === null) {
            self::notFound();
        }

        try {
            $index = SubscriptionSelection::add($item);
        } catch (UserError $error) {
            if ($error->key() === 'subscription.limit_reached') {
                self::toAccount($error->getMessage(), $code);
            }

            self::rememberConfigureState($code, $item, $error);
            self::redirect($back);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.subscriptions.add');
            FlashMessage::error((string) __t('ecommerce.subscriptions.error'), (string) __t('ecommerce.subscriptions.error_title'));
            self::redirect($back);
        }

        $added = SubscriptionSelection::items()[$index] ?? null;

        if (is_array($added)) {
            self::pushEvent(SubscriptionTracking::addToCart(CheckoutSubscriptions::trackingItem($found, $added)));
        }

        FlashMessage::success((string) __t('ecommerce.subscriptions.summary.added', ['name' => $found['price']['full_name']]));
        self::redirect(self::route('ecommerce.checkout.index'));
    }

    /**
     * Il vecchio riepilogo e il suo POST: i servizi si pagano nel checkout,
     * insieme ai prodotti. Il link salvato resta buono (301), un modulo
     * rimasto aperto torna al checkout (303).
     */
    private static function summary(): never
    {
        header('Location: '.self::route('ecommerce.checkout.index'), true, 301);
        exit;
    }

    private static function start(): never
    {
        header('Location: '.self::route('ecommerce.checkout.index'), true, 303);
        exit;
    }

    /** Toglie un servizio dal checkout, con le righe che si appoggiano a lei. */
    private static function remove(): never
    {
        self::requirePost();
        $back = self::route('ecommerce.checkout.index');
        self::verifyCsrf($back);
        self::requireLogin($back);
        $raw = (string) ($_POST['index'] ?? '');
        $items = SubscriptionSelection::items();
        $index = ctype_digit($raw) ? (int) $raw : -1;

        if (!isset($items[$index])) {
            self::redirect($back);
        }

        $children = SubscriptionSelection::children($index);
        $catalog = CheckoutSubscriptions::catalog();
        $tracking = [];

        foreach ([$index, ...$children] as $gone) {
            $found = $catalog[$items[$gone]['price_code']] ?? null;

            if ($found !== null) {
                $tracking[] = CheckoutSubscriptions::trackingItem($found, $items[$gone]);
            }
        }

        if ($tracking !== []) {
            self::pushEvent(SubscriptionTracking::removeFromCart($tracking));
        }

        SubscriptionSelection::remove($index);

        if ($children !== []) {
            FlashMessage::info((string) __t('ecommerce.subscriptions.summary.removed_children', ['count' => (string) count($children)]));
        }

        // Senza servizi né prodotti il checkout rimanda al carrello: meglio i piani.
        self::redirect(SubscriptionSelection::items() === [] && CartSession::peek()['items'] === [] ? self::route('ecommerce.subscriptions.plans') : $back);
    }

    /**
     * Il ritorno da Stripe. Finché la notifica non arriva la pagina dice
     * «stiamo confermando» e si ricarica da sola; confermato, `purchase` parte
     * una volta sola per abbonamento (la sessione ricorda i codici). La
     * selezione si svuota qui, non quando parte Stripe: un pagamento fallito
     * la lascia com'era.
     */
    private static function confirm(string $code): void
    {
        // Se la sessione si è persa durante il giro su Stripe, il login riporta qui.
        self::requireLogin(self::route('ecommerce.subscriptions.confirm').'?abbonamento='.rawurlencode($code));
        $row = $code !== '' ? Subscription::find(['code' => $code, 'channel' => 'online', 'deleted' => 'false'], 1) : null;

        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || (int) $row['customer_id'] !== CartSession::customerId()) {
            self::notFound();
        }

        // Un metodo con redirect rifiutato torna qui: si riprova dal checkout, con la selezione intatta.
        if ((string) ($_GET['redirect_status'] ?? '') === 'failed') {
            FlashMessage::error((string) __t('ecommerce.checkout.pay.failed'), (string) __t('ecommerce.checkout.error_title'));
            self::redirect(self::route('ecommerce.checkout.index'));
        }

        $status = (string) $row['status'];
        $state = in_array($status, Subscription::LIVE, true) ? 'confirmed' : ($status === 'pending' ? 'waiting' : 'failed');

        // Una notifica che non arriva in qualche minuto non arriverà subito: si smette di ricaricare.
        if ($state === 'waiting' && strtotime((string) ($row['creation'] ?? 'now')) < time() - self::WAIT_SECONDS) {
            $state = 'slow';
        }

        // L'ordine partito con l'abbonamento: pagato nella stessa fattura, non è più in sospeso.
        $started = CheckoutSubscriptionStart::started($code);
        $orderNumber = '';

        if ($state !== 'failed') {
            SubscriptionSelection::clear();

            if ((int) ($started['order_id'] ?? 0) > 0) {
                OnlinePayment::forget();
                unset($_SESSION[OnlinePayment::RECEIPT]);
                $orderNumber = (string) (Order::findById((int) $started['order_id'])['order_number'] ?? '');
            }
        }

        $tracked = (array) ($_SESSION[self::TRACKED] ?? []);
        $purchase = $state === 'confirmed' && !in_array($code, $tracked, true);

        if ($purchase) {
            $_SESSION[self::TRACKED] = array_slice([...$tracked, $code], -20);
        }

        if ($state === 'confirmed') {
            CheckoutSubscriptionStart::forget();
        }

        self::seo(
            (string) __t('ecommerce.subscriptions.confirm.title'),
            (string) __t('ecommerce.subscriptions.summary.seo'),
            self::absolute(self::route('ecommerce.subscriptions.confirm')),
            'NOINDEX,NOFOLLOW',
            []
        );

        View::make(Ecommerce::viewPath('pages/subscriptions/confirm.php'), [
            'subscription' => $row,
            'state' => $state,
            'purchase' => $purchase ? SubscriptionTracking::withOrder(SubscriptionTracking::purchase($row), (array) ($started['tracking'] ?? [])) : null,
            'order_number' => $orderNumber,
            'account_url' => self::route('account.subscriptions'),
            'plans_url' => self::route('ecommerce.subscriptions.plans'),
        ])->render();
    }

    /**
     * I campi del servizio: quantità, una voce per personalizzazione del piano
     * (col sovrapprezzo nell'etichetta) e la madre fra le righe già scelte.
     * Li usa anche Aggiungi servizio dell'area cliente, con le righe
     * dell'abbonamento come madri.
     *
     * @param array<string, mixed> $state lo stato dopo un errore
     * @param array<int, string> $parents indice della riga ⇒ etichetta
     * @return array{quantity: mixed, customizations: list<array{field: mixed, help: string}>, parent: mixed}
     */
    public static function configureFields(int $planId, array $state, array $parents): array
    {
        $quantity = FormField::key('quantity')->number()->integer()
            ->label((string) __t('ecommerce.subscriptions.configure.quantity'))
            ->value((string) ($state['quantity'] ?? '1'))
            ->required(true)
            ->attribute('min="1" max="'.SubscriptionSelection::MAX_QUANTITY.'"');

        $customizations = [];

        foreach (Customizations::forPlan($planId) as $definition) {
            $id = (int) $definition['id'];
            $label = (string) $definition['label'];
            $value = (string) ($state['customization'][(string) $id] ?? '');
            $surcharge = (float) $definition['surcharge'];

            if ($definition['kind'] === 'choice') {
                $options = ['' => (string) __t('ecommerce.subscriptions.configure.choose_option')];

                foreach ($definition['options'] as $option) {
                    $extra = (float) $option['surcharge'];
                    $options[(string) $option['id']] = (string) $option['label'].($extra > 0 ? ' (+ '.CartPresenter::money($extra, PlanCatalog::CURRENCY).')' : '');
                }

                $field = FormField::key('customization_'.$id)->select($options);
            } elseif ($definition['kind'] === 'number') {
                $field = FormField::key('customization_'.$id)->number();
                $field = (int) $definition['decimals'] > 0 ? $field->decimals((int) $definition['decimals']) : $field->integer();
            } else {
                $field = FormField::key('customization_'.$id)->text();

                if ((int) $definition['max_length'] > 0) {
                    $field->maxLength((int) $definition['max_length']);
                }
            }

            $field->label($label.($surcharge > 0 ? ' (+ '.CartPresenter::money($surcharge, PlanCatalog::CURRENCY).')' : ''))
                ->inputName('customization['.$id.']')
                ->value($value)
                ->required((bool) $definition['required']);

            if ((int) ($state['field'] ?? 0) === $id && (string) ($state['message'] ?? '') !== '') {
                $field->error((string) $state['message']);
            }

            $customizations[] = ['field' => $field, 'help' => trim((string) $definition['help_text'])];
        }

        $parent = null;

        if ($parents !== []) {
            $selected = (string) ($state['parent'] ?? '');
            $options = count($parents) === 1 ? [] : ['' => (string) __t('ecommerce.subscriptions.configure.choose_option')];

            foreach ($parents as $index => $name) {
                $options[(string) $index] = $name;
            }

            $parent = FormField::key('parent')->select($options)
                ->label((string) __t('ecommerce.subscriptions.configure.parent'))
                ->value($selected !== '' && isset($options[$selected]) ? $selected : (count($parents) === 1 ? (string) array_key_first($parents) : ''))
                ->required(true);
        }

        return ['quantity' => $quantity, 'customizations' => $customizations, 'parent' => $parent];
    }

    /** @return list<int> i piani madre di un piano */
    private static function mothersOf(int $planId): array
    {
        try {
            return array_values(array_map('intval', PlanLinks::parentsOf($planId)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Le righe della selezione che possono fare da madre: righe senza madre
     * il cui piano è fra quelli richiesti.
     *
     * @param list<int> $mothers
     * @param array<string, array{plan: array<string, mixed>, price: array<string, mixed>}> $catalog
     * @return array<int, string>
     */
    private static function eligibleParents(array $mothers, array $catalog): array
    {
        if ($mothers === []) {
            return [];
        }

        $parents = [];

        foreach (SubscriptionSelection::items() as $index => $item) {
            $entry = $catalog[$item['price_code']] ?? null;

            if ($item['parent'] === -1 && $entry !== null && in_array((int) $entry['plan']['id'], $mothers, true)) {
                $parents[$index] = (string) __t('ecommerce.subscriptions.configure.parent_option', [
                    'name' => $entry['price']['full_name'],
                    'row' => (string) ($index + 1),
                ]);
            }
        }

        return $parents;
    }

    public static function configureUrl(string $code): string
    {
        return self::route('ecommerce.subscriptions.configure', ['price' => $code]);
    }

    /** Chi ha già il massimo di abbonamenti aggiunge servizi dall'area cliente. */
    private static function requireRoom(string $priceCode): void
    {
        try {
            Subscriptions::assertRoom(CartSession::customerId());
        } catch (UserError $error) {
            self::toAccount($error->getMessage(), $priceCode);
        }
    }

    /**
     * Al limite di abbonamenti (G10b §7): con un solo abbonamento a cui si
     * possono aggiungere servizi si va dritti ad Aggiungi servizio, col prezzo
     * scelto; altrimenti alla pagina degli abbonamenti, dove ognuno ha il suo.
     */
    private static function toAccount(string $message, string $priceCode = ''): never
    {
        SubscriptionSelection::clear();
        FlashMessage::warning($message, (string) __t('ecommerce.subscriptions.error_title'));
        $editable = self::editableSubscriptions(CartSession::customerId());
        $url = count($editable) === 1 ? self::route('account.subscriptions.add', ['code' => $editable[0]]) : '';

        self::redirect($url !== ''
            ? $url.($priceCode !== '' ? '?price='.rawurlencode($priceCode) : '')
            : self::route('account.subscriptions'));
    }

    /** @return list<string> i codici degli abbonamenti del cliente a cui si aggiungono servizi: in corso, su Stripe, che si rinnovano */
    private static function editableSubscriptions(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        try {
            $found = Subscription::find(
                '`customer_id` = '.$customerId." AND `status` IN ('".implode("','", Subscription::LIVE)."') AND `provider` = 'stripe'"
                ." AND `provider_reference` <> '' AND `auto_renew` = 'true' AND `deleted` = 'false'",
                null,
                'id',
                'DESC'
            );
        } catch (Throwable) {
            return [];
        }

        $rows = !is_array($found) || $found === [] ? [] : (isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array')));

        return array_values(array_map(static fn (array $row): string => (string) $row['code'], $rows));
    }

    /**
     * Senza errore (token scaduto) si ricordano solo i valori: l'avviso lo dà verifyCsrf.
     *
     * @param array<string, mixed> $item
     */
    private static function rememberConfigureState(string $code, array $item, ?UserError $error = null): void
    {
        $field = $error?->field() ?? 0;
        $_SESSION[self::CONFIGURE_STATE] = [
            'price' => $code,
            'quantity' => (string) $item['quantity'],
            'customization' => $item['customization'],
            'parent' => $item['parent'] >= 0 ? (string) $item['parent'] : '',
            'field' => max(0, $field),
            'message' => $error?->getMessage() ?? '',
        ];

        if ($error !== null && $field <= 0) {
            FlashMessage::error($error->getMessage(), (string) __t('ecommerce.subscriptions.error_title'));
        }
    }

    /** @return array<string, mixed> */
    private static function pullConfigureState(string $code): array
    {
        $state = $_SESSION[self::CONFIGURE_STATE] ?? null;
        unset($_SESSION[self::CONFIGURE_STATE]);

        return is_array($state) && ($state['price'] ?? '') === $code ? $state : [];
    }

    /** @param array<string, mixed> $event */
    private static function pushEvent(array $event): void
    {
        $_SESSION[self::EVENTS] = array_slice([...(array) ($_SESSION[self::EVENTS] ?? []), $event], -10);
    }

    /** @return list<array<string, mixed>> */
    private static function pullEvents(): array
    {
        $events = array_values(array_filter((array) ($_SESSION[self::EVENTS] ?? []), 'is_array'));
        unset($_SESSION[self::EVENTS]);

        return $events;
    }

    private static function requirePost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::notFound();
        }
    }

    /** Token non valido (pagina scaduta): avviso 929, valori ricordati da `$remember` e ritorno a `$back`. */
    private static function verifyCsrf(string $back, ?callable $remember = null): void
    {
        if (Csrf::verify()) {
            return;
        }

        if ($remember !== null) {
            $remember();
        }
        Csrf::flashFailure();
        self::redirect($back);
    }



    /** @param array<string, string> $values */
    private static function rememberFormState(array $values): void
    {
        $_SESSION['ecommerce_subscription_form'] = $values;
    }

    /** @return array<string, string> */
    private static function pullFormState(): array
    {
        $values = (array) ($_SESSION['ecommerce_subscription_form'] ?? []);
        unset($_SESSION['ecommerce_subscription_form']);

        return array_map('strval', $values);
    }

    /** Senza account si passa dal login, che poi torna qui (G10.11). */
    private static function requireLogin(string $continue): void
    {
        if (!CartSession::authenticated()) {
            self::redirect(self::route('ecommerce.auth.login').'?continue='.rawurlencode($continue));
        }
    }

    /**
     * @param list<array{url: string, name: string}> $breadcrumbs
     */
    private static function seo(string $title, string $description, string $url, string $robots, array $breadcrumbs): void
    {
        global $SEO, $SOCIETY;

        $society = trim((string) ($SOCIETY->name ?? ''));
        $SEO->title = $title.($society !== '' && $robots === 'INDEX,FOLLOW' ? ' - '.$society : '');
        $SEO->description = $description;
        $SEO->url = $url;
        $SEO->robots = $robots;
        $SEO->breadcrumb = [];

        foreach ($breadcrumbs as $item) {
            $SEO->breadcrumb[self::absolute((string) $item['url'])] = (string) $item['name'];
        }
    }

    public static function absolute(string $url): string
    {
        if (preg_match('~^https?://~', $url) === 1) {
            return $url;
        }

        $site = rtrim((string) ($GLOBALS['PATH']->site ?? (defined('APP_URL') ? APP_URL : '')), '/');

        return $site.'/'.ltrim($url, '/');
    }

    private static function route(string $name, array $parameters = []): string
    {
        try {
            $url = (string) \__r($name, $parameters);
        } catch (Throwable) {
            $url = '';
        }

        return $url !== '' ? $url : '/abbonamenti/';
    }

    private static function redirect(string $url): never
    {
        header('Location: '.$url);
        exit;
    }

    private static function notFound(): never
    {
        throw new NotFoundHttpException();
    }
}
