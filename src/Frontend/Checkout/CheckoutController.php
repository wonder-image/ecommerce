<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use OutOfBoundsException;
use RuntimeException;
use Throwable;
use Wonder\App\Security\RecaptchaChallenge;
use Wonder\App\Security\RecaptchaGuard;
use Wonder\App\Support\GoogleMaps;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Http\Csrf;
use Wonder\Auth\Frontend\AuthValidator;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Support\Throttle;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\View\View;

final class CheckoutController
{
    private const FORM_STATE = 'ecommerce_checkout_form_state';
    private const COMPLETED = 'ecommerce_checkout_completed';
    // Senza JavaScript: il modulo torna con i link per accedere o recuperare la password.
    private const ACCOUNT_EXISTS = 'ecommerce_checkout_account_exists';
    // Dieci codici sbagliati in dieci minuti: abbastanza per un errore di battitura, poco per indovinarne uno.
    private const COUPON_LIMIT = 10;
    private const COUPON_WINDOW = 600;

    public static function handle(string $action): void
    {
        match ($action) {
            'index' => self::index(),
            'place' => self::place(),
            'summary' => self::summary(),
            'coupon' => self::coupon(),
            'completed' => self::completed(),
            'return' => self::returned(),
            'pay' => self::pay(),
            'reopen' => self::reopen(),
            'declined' => self::declined(),
            default => self::notFound(),
        };
    }


    private static function index(): void
    {
        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        // Il carrello è diventato l'ordine in pagamento: torna carrello e il checkout resta modificabile.
        self::resume(false);
        $cart = CartSession::current(false);
        if ((array) ($cart['items'] ?? []) === []) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        $formState = self::pullFormState();
        $fromCart = array_filter(CheckoutPlacement::fromCart((array) ($cart['order'] ?? [])), static fn (string $v): bool => $v !== '' && $v !== '0');
        // Senza il POST le scelte della fatturazione si rileggono dal carrello: ricaricando restano quelle fatte.
        $values = $formState !== [] ? self::defaults($formState) : CheckoutRules::choices((array) ($cart['order'] ?? [])) + $fromCart + self::defaults([]);
        // Riaperto dopo un pagamento rifiutato: torna spuntata la scelta Stripe di prima (Klarna…), non la carta.
        $choice = OnlinePayment::pullChoice();
        if ($formState === [] && $choice !== '' && CheckoutRules::splitPayment($choice)[0] === (int) ($values['payment_method_id'] ?? 0)) {
            $values['payment_method_id'] = $choice;
        }
        // Alla prima visita le scelte di consegna e pagamento restano quelle del carrello.
        $summary = self::initialSummary((int) ($cart['order']['id'] ?? 0), $values, $formState === []);
        // L'anteprima riscrive spedizione e commissione sul carrello: la pagina parte da quello aggiornato.
        $cart = CartSession::current(false);
        $user = CartSession::user();
        $billing = Order::billingAddress()->formSchema($values['billing_country'] ?? null);

        self::seo((string) __t('ecommerce.checkout.title'), self::route('ecommerce.checkout.index'));
        View::make(Ecommerce::viewPath('pages/checkout/index.php'), [
            'cart' => $cart,
            'summary' => $summary,
            'values' => $values,
            'shipping_fields' => self::fields(array_diff_key(
                Order::shippingAddress()->formSchema($values['shipping_country'] ?? null),
                array_flip(['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'])
            ), $values),
            // Il cellulare si chiede una volta, nei contatti.
            'billing_fields' => self::fields(array_diff_key(
                $billing,
                array_flip(['billing_type', 'billing_business_name', 'billing_cf', 'billing_pi', 'billing_sdi', 'billing_pec', 'billing_phone_prefix', 'billing_phone'])
            ), $values),
            'invoice_fields' => self::fields(CheckoutFields::pick($billing, CheckoutFields::INVOICE), $values),
            'consents' => CheckoutRules::askedConsents((int) ($user->id ?? 0)),
            'express' => ExpressCheckout::buttons((array) ($cart['order'] ?? [])),
            'user_email' => CartSession::authenticated() ? trim((string) ($user->email ?? '')) : '',
            'guest' => !CartSession::authenticated(),
            'account_exists' => self::pullAccountExists(),
        ])->render();
    }

    /**
     * La prima anteprima, già nella pagina: le scelte di consegna si vedono
     * anche senza JavaScript. Se il gestionale non risponde, la pagina resta
     * quella di prima e il JavaScript riprova al primo cambiamento.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>|null
     */
    private static function initialSummary(int $cartId, array $values, bool $keepCart): ?array
    {
        try {
            return CheckoutSummary::payload($cartId, CheckoutRules::post($values, CartSession::user()), CartSession::user(), $keepCart);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.index');

            return null;
        }
    }


    /**
     * L'ordine nasce qui. Un metodo manuale porta alla pagina «completato»;
     * un metodo online risponde al JavaScript col segreto dell'intento, e
     * l'ordine resta in sessione finché il denaro non arriva.
     */
    private static function place(): void
    {
        self::requirePost();
        $json = self::wantsJson();
        if ($json && !Csrf::verify()) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }
        self::requireCsrf();

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::leave(self::loginUrl(), $json, 401);
        }

        $post = CheckoutRules::post($_POST, CartSession::user());
        $user = CartSession::user();

        try {
            if (!CartSession::authenticated()) {
                RecaptchaGuard::for('ecommerce_checkout')
                    ->withService('ecommerce-checkout')
                    ->withLogAction('place')
                    ->verify();
            }

            // Un ordine in pagamento (un'altra scheda, senza JavaScript) torna
            // carrello e si unisce a quello nato nel frattempo: il modulo vale
            // per tutti e due.
            self::resume($json);

            $cartId = self::cartId();
            if ($cartId === 0) {
                if ($json) {
                    self::leave(self::route('ecommerce.cart.index'), true, 409);
                }

                throw new RuntimeException((string) __t('ecommerce.checkout.errors.empty'));
            }

            $placed = CheckoutPlacement::place($cartId, $post, $user, ['json' => $json, 'expected_total' => $_POST['expected_total'] ?? null]);
            $receipt = $placed['receipt'];

            if (!$placed['online']) {
                $_SESSION[self::COMPLETED] = $receipt;
                self::leave(self::route('ecommerce.checkout.completed'), $json);
            }

            try {
                $started = CheckoutPlacement::startOnline($receipt);
            } catch (Throwable $error) {
                // L'ordine c'è: la pagina di pagamento riprova a creare l'intento.
                Errors::internal($error, 'ecommerce.checkout.start', ['order_id' => $receipt['order_id']]);
                self::json(['success' => false, 'redirect' => self::route('ecommerce.checkout.pay')], 502);
            }

            self::json(['success' => true] + $started);
        } catch (CheckoutRejected $e) {
            // Il Payment Element mostra un importo: se è cambiato, il cliente lo rivede prima di pagare.
            if ($e->totalChanged()) {
                self::json(['success' => false, 'error' => 'total_changed', 'message' => $e->getMessage(), 'summary' => $e->summary()], 409);
            }

            self::reject($e->errors(), $post, $json);
        } catch (RecaptchaChallenge $e) {
            // Punteggio basso: il JavaScript apre la casella; senza JSON il modulo torna con la casella aperta (segno in sessione).
            if ($json) {
                self::json(['success' => false, 'status' => RecaptchaChallenge::CHALLENGE_CODE, 'errors' => [$e->getMessage()]], 422);
            }

            self::reject([$e->getMessage()], $post, $json);
        } catch (GuestAccountExists $e) {
            self::accountExists($e, $post, $json);
        } catch (UserError $error) {
            self::reject([$error->getMessage()], $post, $json);
        } catch (RuntimeException $error) {
            self::reject([$error->getMessage()], $post, $json);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.place');
            self::reject([(string) __t('ecommerce.checkout.errors.generic')], $post, $json);
        }
    }

    private static function summary(): never
    {
        $cartId = self::guardJson();

        try {
            self::json(['success' => true] + CheckoutSummary::payload($cartId, CheckoutRules::post($_POST, CartSession::user()), CartSession::user()));
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.summary');
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
        }
    }

    private static function coupon(): void
    {
        $return = ($_POST['return'] ?? '') === 'cart' ? 'cart' : 'checkout';
        $json = self::wantsJson();
        if (Csrf::verify()) {
            // Il coupon cambia il totale: l'ordine in pagamento torna carrello.
            self::resume($json);
        }
        // Il coupon si prova già dal carrello, prima del login.
        $cartId = $json ? self::guardJson($return !== 'cart') : self::guardPage($return !== 'cart');
        $action = (string) ($_POST['action'] ?? 'apply') === 'remove' ? 'remove' : 'apply';
        $back = $return === 'cart' ? self::route('ecommerce.cart.index') : self::route('ecommerce.checkout.index');

        $status = 200;

        try {
            if ($action === 'apply' && !self::couponAllowed($cartId)) {
                // Troppi codici sbagliati: niente tentativo, il riepilogo torna com'è.
                $status = 429;
                $payload = CheckoutSummary::payload($cartId, $_POST, CartSession::user(), !$json)
                    + ['error' => (string) __t('ecommerce.checkout.errors.too_many_coupons')];
            } else {
                // Senza JavaScript il form del coupon non porta il resto del modulo: valgono le scelte del carrello.
                $payload = CheckoutSummary::coupon($cartId, $action, (string) ($_POST['code'] ?? ''), $_POST, CartSession::user(), !$json);
                if ($action === 'apply' && $payload['error'] !== '') {
                    self::couponFailed($cartId);
                }
            }
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.coupon');

            if ($json) {
                self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
            }

            $payload = ['error' => (string) __t('ecommerce.checkout.errors.generic')];
        }

        if ($json) {
            self::json(['success' => $payload['error'] === ''] + $payload, $status);
        }

        $errors = $payload['error'] !== '' ? [$payload['error']] : [];
        $notice = $errors === [] ? (string) __t('ecommerce.checkout.'.($action === 'remove' ? 'coupon_removed' : 'coupon_applied')) : '';
        $title = (string) __t('ecommerce.'.($return === 'cart' ? 'cart' : 'checkout').'.'.($errors === [] ? 'notice_title' : 'error_title'));
        if ($errors === []) {
            FlashMessage::success($notice, $title);
        } else {
            FlashMessage::error(implode("\n", $errors), $title);
        }
        self::redirect($back);
    }

    /** I codici sbagliati si contano per indirizzo e per carrello: chi cambia carrello o rete trova l'altro limite. */
    private static function couponAllowed(int $cartId): bool
    {
        return Throttle::allowed('coupon_ip', Throttle::ip(), self::COUPON_LIMIT, self::COUPON_WINDOW)
            && Throttle::allowed('coupon_cart', (string) $cartId, self::COUPON_LIMIT, self::COUPON_WINDOW);
    }

    private static function couponFailed(int $cartId): void
    {
        Throttle::hit('coupon_ip', Throttle::ip(), self::COUPON_WINDOW);
        Throttle::hit('coupon_cart', (string) $cartId, self::COUPON_WINDOW);
    }

    /** Le guardie delle rotte JSON: POST, CSRF, login; ridà l'id del carrello. */
    private static function guardJson(bool $login = true): int
    {
        self::requirePost();

        if (!Csrf::verify()) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }

        if ($login && !self::guestAllowed() && !CartSession::authenticated()) {
            self::json(['success' => false, 'redirect' => self::loginUrl()], 401);
        }

        $cartId = self::cartId();

        return $cartId > 0
            ? $cartId
            : self::json(['success' => false, 'redirect' => self::route('ecommerce.cart.index')], 409);
    }

    /** Le stesse guardie per la pagina senza JavaScript. */
    private static function guardPage(bool $login = true): int
    {
        self::requirePost();
        self::requireCsrf();

        if ($login && !self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $cartId = self::cartId();

        return $cartId > 0 ? $cartId : self::redirect(self::route('ecommerce.cart.index'));
    }

    /** L'id del carrello del cliente, 0 se non c'è o è vuoto. */
    private static function cartId(): int
    {
        $cart = CartSession::current(false);

        return (array) ($cart['items'] ?? []) === [] ? 0 : (int) ($cart['order']['id'] ?? 0);
    }

    private static function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Fuori dal checkout: in JSON un indirizzo da seguire, altrimenti un redirect. */
    private static function leave(string $url, bool $json, int $status = 200): never
    {
        if ($json) {
            self::json(['success' => $status === 200, 'redirect' => $url], $status);
        }

        self::redirect($url);
    }

    /** Errori da mostrare sul modulo: in JSON tornano al JavaScript, altrimenti in sessione. */
    private static function reject(array $errors, array $post, bool $json): never
    {
        if ($json) {
            self::json(['success' => false, 'errors' => array_values($errors)], 422);
        }

        self::rememberErrors($errors, CheckoutRules::rememberedPost($post, $_POST));
        self::redirect(self::route('ecommerce.checkout.index'));
    }

    /**
     * L'email dell'ospite è di un account: in JSON un 409 con i link per
     * accedere o recuperare la password, altrimenti il modulo torna com'era
     * con l'avviso e gli stessi link.
     */
    private static function accountExists(GuestAccountExists $error, array $post, bool $json): never
    {
        if ($json) {
            self::json(['success' => false, 'error' => 'account_exists', 'message' => $error->getMessage()] + GuestAccountExists::links(), 409);
        }

        $_SESSION[self::ACCOUNT_EXISTS] = true;
        self::rememberErrors([$error->getMessage()], CheckoutRules::rememberedPost($post, $_POST));
        self::redirect(self::route('ecommerce.checkout.index'));
    }

    private static function pullAccountExists(): bool
    {
        $exists = !empty($_SESSION[self::ACCOUNT_EXISTS]);
        unset($_SESSION[self::ACCOUNT_EXISTS]);

        return $exists;
    }

    private static function completed(): void
    {
        $result = (array) ($_SESSION[self::COMPLETED] ?? []);
        if (empty($result['order_id'])) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        self::seo((string) __t('ecommerce.checkout.completed.title'), self::route('ecommerce.checkout.completed'));
        View::make(Ecommerce::viewPath('pages/checkout/completed.php'), [
            'result' => $result,
        ])->render();
    }

    /**
     * Il ritorno dal gateway. L'esito si chiede a Stripe e vale solo per
     * l'ordine in sessione: un `payment_intent` estraneo è un 404.
     */
    private static function returned(): void
    {
        $orderId = OnlinePayment::sessionOrder();
        if ($orderId <= 0) {
            self::notFound();
        }

        $reference = $_GET['payment_intent'] ?? '';
        if (!is_string($reference)) {
            self::notFound();
        }

        try {
            $outcome = OnlinePayment::settle($orderId, $reference);
        } catch (OutOfBoundsException) {
            self::notFound();
        }

        if ($outcome === 'retry' || $outcome === 'canceled') {
            // Si torna al checkout, che riapre l'ordine: si riprova o si cambia metodo.
            // L'ordine della barra rapida si riprova da «Paga ora».
            FlashMessage::error((string) __t('ecommerce.checkout.pay.'.($outcome === 'retry' ? 'failed' : 'canceled')), (string) __t('ecommerce.checkout.error_title'));
            self::redirect(self::route(ExpressCart::owns($orderId) ? 'ecommerce.checkout.pay' : 'ecommerce.checkout.index'));
        }

        OnlinePayment::forget();
        try {
            // Pagato dalla barra rapida: dal carrello vero vanno via le righe comprate.
            ExpressCart::settled($orderId);
        } catch (Throwable $e) {
            Errors::internal($e, 'ecommerce.checkout.express_settle');
        }
        $receipt = (array) ($_SESSION[OnlinePayment::RECEIPT] ?? []);
        unset($_SESSION[OnlinePayment::RECEIPT]);
        $order = (array) Order::findById($orderId);
        if ((int) ($receipt['order_id'] ?? 0) === $orderId) {
            // Il numero arriva con l'incasso (o l'elaborazione): a «Paga» non c'era.
            $receipt['order_number'] = (string) ($order['order_number'] ?? '');
        } else {
            $receipt = [
                'order_id' => $orderId,
                'order_number' => (string) ($order['order_number'] ?? $order['code'] ?? ''),
                'total' => (string) ($order['total'] ?? ''),
                'guest' => !CartSession::authenticated(),
            ];
        }

        // L'email «pagato» parte con l'incasso; in lavorazione arriverà più tardi.
        // Pagato non è confermato: lo conferma il commerciante, visto la merce.
        $_SESSION[self::COMPLETED] = [
            'processing' => $outcome === 'processing',
            'paid' => $outcome === 'succeeded',
            'email_sent' => !empty($receipt['guest']) && $outcome === 'succeeded',
        ] + $receipt;
        self::redirect(self::route('ecommerce.checkout.completed'));
    }

    /** La pagina per pagare l'ordine in sospeso della sessione, anche dopo un rifiuto. */
    private static function pay(): void
    {
        $orderId = OnlinePayment::pending();
        if ($orderId === 0) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        try {
            $clientSecret = OnlinePayment::start($orderId)['client_secret'];
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.pay', ['order_id' => $orderId]);
            $clientSecret = '';
        }

        self::seo((string) __t('ecommerce.checkout.pay.title'), self::route('ecommerce.checkout.pay'));
        $order = (array) Order::findById($orderId);
        View::make(Ecommerce::viewPath('pages/checkout/pay.php'), [
            'order' => $order,
            'billing_details' => OnlinePayment::billingDetails($order),
            'client_secret' => $clientSecret,
            'stripe' => OnlinePayment::browserKeys(),
            'return_url' => self::route('ecommerce.checkout.return'),
            // Il checkout riapre l'ordine; quello della barra rapida non ha un checkout.
            'back_url' => ExpressCart::owns($orderId) ? '' : self::route('ecommerce.checkout.index'),
        ])->render();
    }

    /**
     * La prima modifica del checkout dopo un «Paga» non andato: l'ordine in
     * pagamento torna carrello sulla stessa riga e il prossimo «Paga» lo rifà
     * ordine. Se intanto il denaro è arrivato si va al ritorno.
     */
    private static function reopen(): never
    {
        self::requirePost();
        $json = self::wantsJson();
        if ($json && !Csrf::verify()) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }
        self::requireCsrf();

        $reopened = self::resume($json, false);
        if (!$json) {
            self::flashRemoved($reopened['removed']);
            self::redirect(self::route('ecommerce.checkout.index'));
        }

        $cartId = self::cartId();
        if ($cartId === 0) {
            self::leave(self::route('ecommerce.cart.index'), true, 409);
        }

        try {
            $payload = CheckoutSummary::payload($cartId, CheckoutRules::post($_POST, CartSession::user()), CartSession::user());
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.reopen');
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
        }
        if ($reopened['removed'] !== []) {
            $payload['notices'] = array_merge((array) ($payload['notices'] ?? []), [self::removedNotice($reopened['removed'])]);
        }

        self::json(['success' => true] + $payload);
    }

    /**
     * Il browser dice che Stripe ha rifiutato la carta nel modulo: l'esito si
     * chiede a Stripe per l'ordine in sessione, così il rifiuto resta nella
     * storia del pagamento anche senza webhook. Il client non porta niente.
     */
    private static function declined(): never
    {
        self::requirePost();
        if (!Csrf::verify()) {
            self::json(['success' => false], 419);
        }

        $orderId = OnlinePayment::pending();
        // Ogni chiamata interroga Stripe: venti per ordine in dieci minuti bastano a molte carte.
        if ($orderId > 0 && !Throttle::allowed('declined_order', (string) $orderId, 20, 600)) {
            self::json(['success' => false], 429);
        }
        Throttle::hit('declined_order', (string) $orderId, 600);
        $reference = $orderId > 0 ? (string) (OnlinePayments::payment($orderId)['provider_reference'] ?? '') : '';
        if ($reference === '') {
            self::json(['success' => false], 404);
        }

        try {
            $outcome = OnlinePayment::settle($orderId, $reference);
        } catch (OutOfBoundsException) {
            self::json(['success' => false], 404);
        }

        // Pagato davvero (il browser si sbagliava): si va al ritorno.
        if (in_array($outcome, ['succeeded', 'processing'], true)) {
            self::leave(self::returnUrl($reference), true);
        }

        self::json(['success' => true, 'outcome' => $outcome]);
    }

    /**
     * L'ordine in pagamento del checkout normale torna carrello prima di una
     * modifica. Se il denaro è arrivato si va al ritorno; se l'intento non si
     * chiude (pagamento in corso) si resta a pagare, col messaggio.
     *
     * @return array{outcome: string, reference: string, removed: list<string>}
     */
    private static function resume(bool $json, bool $flash = true): array
    {
        $reopened = OnlinePayment::resume();
        if (in_array($reopened['outcome'], ['succeeded', 'processing'], true)) {
            self::leave(self::returnUrl($reopened['reference']), $json);
        }
        if ($reopened['outcome'] === 'kept') {
            FlashMessage::error((string) ($reopened['error'] ?? ''), (string) __t('ecommerce.checkout.error_title'));
            self::leave(self::route('ecommerce.checkout.pay'), $json);
        }
        if ($flash) {
            self::flashRemoved($reopened['removed']);
        }

        return $reopened;
    }

    private static function returnUrl(string $reference): string
    {
        return self::route('ecommerce.checkout.return').'?payment_intent='.rawurlencode($reference);
    }

    /** @param list<string> $names */
    private static function removedNotice(array $names): string
    {
        return (string) __t('ecommerce.checkout.pay.removed', ['names' => implode(', ', $names)]);
    }

    /** @param list<string> $names */
    private static function flashRemoved(array $names): void
    {
        if ($names !== []) {
            FlashMessage::info(self::removedNotice($names), (string) __t('ecommerce.checkout.notice_title'));
        }
    }

    /** @return array<string, string> */
    private static function defaults(array $submitted): array
    {
        if ($submitted !== []) {
            return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $submitted);
        }

        $user = CartSession::user();
        if (!CartSession::authenticated()) {
            return ['billing_country' => 'IT', 'shipping_country' => 'IT'];
        }

        $contactId = CartSession::customerId();
        $contact = Contact::findById($contactId);
        $contact = is_array($contact) ? $contact : [];
        $shipping = ContactAddress::find(['contact_id' => $contactId, 'is_default' => 'true', 'deleted' => 'false'], 1)
            ?: ContactAddress::find(['contact_id' => $contactId, 'deleted' => 'false'], 1);
        $shipping = is_array($shipping) ? $shipping : [];

        $values = ['email' => (string) ($user->email ?? $contact['email'] ?? '')];
        foreach (Order::billingAddress()->keys() as $key) {
            $source = substr($key, strlen('billing_'));
            $values[$key] = (string) ($contact[$source] ?? '');
        }
        foreach (Order::shippingAddress()->keys() as $key) {
            $source = substr($key, strlen('shipping_'));
            $values[$key] = (string) ($shipping[$source] ?? '');
        }
        // Place ID e coordinate seguono l'indirizzo: l'aiuto di Google li svuota se lo si cambia a mano.
        foreach (GoogleMaps::cleanGeo($shipping) as $key => $value) {
            $values['shipping_'.$key] = $value === null ? '' : (string) $value;
        }
        $values['billing_country'] = $values['billing_country'] ?: 'IT';
        $values['shipping_country'] = $values['shipping_country'] ?: 'IT';

        // Il cellulare del contatto: il prefisso davanti al numero lo toglie il core.
        if ($values['shipping_phone'] === '') {
            $prefix = (string) ($contact['phone_prefix'] ?? '');
            $phone = (string) (($contact['phone'] ?? '') ?: ($user->phone ?? ''));
            $values['shipping_phone_prefix'] = $prefix;
            $values['shipping_phone'] = AuthValidator::localPhone($phone, $prefix);
        }
        if ($values['shipping_phone_prefix'] === '' && function_exists('countryPhonePrefix')) {
            $values['shipping_phone_prefix'] = (string) countryPhonePrefix($values['shipping_country']);
        }
        if ($values['shipping_phone_prefix'] === '') {
            $values['shipping_phone_prefix'] = AuthValidator::defaultPrefix();
        }

        return $values;
    }

    /**
     * I campi con valore e label della lingua: lo schema dell'indirizzo non
     * ne ha e il campo scriverebbe la sua chiave.
     *
     * @return array<string, object>
     */
    private static function fields(array $schema, array $values): array
    {
        $labels = Order::shippingAddress()->labels() + Order::billingAddress()->labels();

        foreach ($schema as $key => $field) {
            if (!is_object($field)) {
                continue;
            }
            if (isset($labels[$key]) && method_exists($field, 'label')) {
                $field->label((string) $labels[$key]);
            }
            if (CheckoutFields::required($key) && method_exists($field, 'required')) {
                $field->required();
            }
            if (CheckoutFields::stateRequired($key) && method_exists($field, 'context')) {
                $field->context('required_when_states_available', true);
            }
            if (method_exists($field, 'value') && array_key_exists($key, $values)) {
                $field->value((string) $values[$key]);
            }
        }

        return $schema;
    }

    private static function guestAllowed(): bool
    {
        return GuestCheckout::enabled();
    }

    private static function loginUrl(): string
    {
        return self::route('ecommerce.auth.login').'?continue='.rawurlencode(self::route('ecommerce.checkout.index'));
    }

    private static function rememberErrors(array $errors, array $values = []): void
    {
        unset(
            $values[Csrf::FIELD],
            $values['g-recaptcha-token'],
            $values['g-recaptcha-action'],
            $values['g-recaptcha-type']
        );
        $errors = array_values(array_filter(array_map('strval', $errors)));
        if ($errors !== []) {
            FlashMessage::error(implode("\n", $errors), (string) __t('ecommerce.checkout.error_title'));
        }

        $_SESSION[self::FORM_STATE] = $values;
    }

    /** @return array<string, mixed> */
    private static function pullFormState(): array
    {
        $values = $_SESSION[self::FORM_STATE] ?? [];
        unset($_SESSION[self::FORM_STATE]);

        return is_array($values) ? $values : [];
    }

    private static function seo(string $title, string $url): void
    {
        global $SEO;

        $SEO->title = $title;
        $SEO->description = (string) __t('ecommerce.checkout.seo');
        $SEO->url = $url;
        $SEO->breadcrumb = [];
        $SEO->robots = 'NOINDEX,NOFOLLOW';
    }

    private static function requirePost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::notFound();
        }
    }

    private static function requireCsrf(): void
    {
        if (!Csrf::verify()) {
            http_response_code(419);
            exit('CSRF token invalid');
        }
    }

    private static function route(string $name): string
    {
        return \__r($name) ?: '/';
    }

    private static function redirect(string $url): never
    {
        header('Location: '.$url);
        exit;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        exit;
    }
}
