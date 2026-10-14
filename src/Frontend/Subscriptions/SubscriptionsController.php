<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\View\View;

/**
 * Gli abbonamenti sul sito (G10 §2.1 e §5): la pagina pubblica dei piani, la
 * sottoscrizione (account obbligatorio, dati di fatturazione, codice sconto,
 * poi la pagina di Stripe) e il ritorno, che aspetta la notifica di Stripe.
 *
 * Senza `online_subscriptions` nessuna di queste pagine esiste (404).
 *
 * Le quattro domande del frontend:
 * - **piani**: `INDEX,FOLLOW`, canonical assoluto senza parametri, `ItemList`
 *   di `Product` con gli importi visibili, breadcrumb Home › Abbonamenti,
 *   `view_item_list` e `select_item`;
 * - **sottoscrizione** e **conferma**: `NOINDEX,NOFOLLOW`, nessun dato
 *   strutturato, `$SEO->breadcrumb = []`; `begin_checkout` alla vista,
 *   `purchase` una volta sola alla conferma.
 */
final class SubscriptionsController
{
    /** Le conferme già mandate a GTM in questa sessione: `purchase` non si ripete. */
    public const TRACKED = 'ecommerce_subscription_purchases';

    /** Quanto la conferma si ricarica da sola aspettando la notifica di Stripe. */
    public const WAIT_SECONDS = 180;

    public static function handle(string $action, array $parameters = []): void
    {
        if (!Gestionale::feature('online_subscriptions')) {
            self::notFound();
        }

        match ($action) {
            'plans' => self::plans(),
            'subscribe' => self::subscribe((string) ($parameters['price'] ?? '')),
            'start' => self::start((string) ($parameters['price'] ?? '')),
            'confirm' => self::confirm((string) ($_GET['abbonamento'] ?? '')),
            default => self::notFound(),
        };
    }

    private static function plans(): void
    {
        $plans = PlanCatalog::present(PlanCatalog::load(), static fn (string $code): string => self::route('ecommerce.subscriptions.subscribe', ['price' => $code]));
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
        ])->render();
    }

    /** Il riepilogo del prezzo, la fatturazione e il codice sconto: il passo prima di Stripe. */
    private static function subscribe(string $code): void
    {
        self::requireLogin(self::route('ecommerce.subscriptions.subscribe', ['price' => $code]));
        $found = PlanCatalog::find($code, static fn (string $c): string => self::route('ecommerce.subscriptions.subscribe', ['price' => $c]));

        if ($found === null) {
            self::notFound();
        }

        $contact = Contact::findById(CartSession::customerId());
        $values = self::pullFormState() + self::contactValues(is_array($contact) ? $contact : []);

        self::seo(
            (string) __t('ecommerce.subscriptions.subscribe.title', ['plan' => $found['plan']['name']]),
            (string) __t('ecommerce.subscriptions.subscribe.seo'),
            self::absolute(self::route('ecommerce.subscriptions.subscribe', ['price' => $code])),
            'NOINDEX,NOFOLLOW',
            []
        );

        View::make(Ecommerce::viewPath('pages/subscriptions/subscribe.php'), [
            'plan' => $found['plan'],
            'price' => $found['price'],
            'values' => $values,
            'billing_fields' => self::billingFields($values),
            'include_tax' => PlanCatalog::pricesIncludeTax(),
            'currency' => PlanCatalog::CURRENCY,
            'plans_url' => self::route('ecommerce.subscriptions.plans'),
            'action' => self::route('ecommerce.subscriptions.start', ['price' => $code]),
            'csrf_token' => AuthSession::csrfToken(),
        ])->render();
    }

    /** Il POST: crea l'abbonamento in attesa e manda il cliente sulla pagina di Stripe. */
    private static function start(string $code): never
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::notFound();
        }

        $back = self::route('ecommerce.subscriptions.subscribe', ['price' => $code]);

        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(419);
            exit('CSRF token invalid');
        }

        self::requireLogin($back);
        $billing = array_filter(
            (array) $_POST,
            static fn ($value, $key): bool => is_string($key) && str_starts_with($key, 'billing_') && is_scalar($value),
            ARRAY_FILTER_USE_BOTH
        );

        $billing = array_map(static fn ($value): string => trim((string) $value), $billing);

        if (!SubscriptionBilling::complete($billing)) {
            self::rememberFormState($billing + ['coupon' => (string) ($_POST['coupon'] ?? '')]);
            FlashMessage::error((string) __t('ecommerce.checkout.errors.billing'), (string) __t('ecommerce.subscriptions.error_title'));
            self::redirect($back);
        }

        try {
            $started = OnlineSubscriptions::start(
                CartSession::customerId(),
                $code,
                $billing,
                trim((string) ($_POST['coupon'] ?? '')),
                [
                    'success' => self::absolute(self::route('ecommerce.subscriptions.confirm')).'?abbonamento={SUBSCRIPTION}',
                    'cancel' => self::absolute($back),
                ]
            );
        } catch (UserError $error) {
            self::rememberFormState($billing + ['coupon' => (string) ($_POST['coupon'] ?? '')]);
            FlashMessage::error($error->getMessage(), (string) __t('ecommerce.subscriptions.error_title'));
            self::redirect($back);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.subscriptions.start');
            FlashMessage::error((string) __t('ecommerce.subscriptions.error'), (string) __t('ecommerce.subscriptions.error_title'));
            self::redirect($back);
        }

        self::redirect($started['url']);
    }

    /**
     * Il ritorno da Stripe. Finché la notifica non arriva la pagina dice
     * «stiamo confermando» e si ricarica da sola; confermato, `purchase` parte
     * una volta sola per abbonamento (la sessione ricorda i codici).
     */
    private static function confirm(string $code): void
    {
        // Se la sessione si è persa durante il giro su Stripe, il login riporta qui.
        self::requireLogin(self::route('ecommerce.subscriptions.confirm').'?abbonamento='.rawurlencode($code));
        $row = $code !== '' ? Subscription::find(['code' => $code, 'channel' => 'online', 'deleted' => 'false'], 1) : null;

        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || (int) $row['customer_id'] !== CartSession::customerId()) {
            self::notFound();
        }

        $status = (string) $row['status'];
        $state = in_array($status, Subscription::LIVE, true) ? 'confirmed' : ($status === 'pending' ? 'waiting' : 'failed');

        // Una notifica che non arriva in qualche minuto non arriverà subito: si smette di ricaricare.
        if ($state === 'waiting' && strtotime((string) ($row['creation'] ?? 'now')) < time() - self::WAIT_SECONDS) {
            $state = 'slow';
        }
        $tracked = (array) ($_SESSION[self::TRACKED] ?? []);
        $purchase = $state === 'confirmed' && !in_array($code, $tracked, true);

        if ($purchase) {
            $_SESSION[self::TRACKED] = array_slice([...$tracked, $code], -20);
        }

        self::seo(
            (string) __t('ecommerce.subscriptions.confirm.title'),
            (string) __t('ecommerce.subscriptions.subscribe.seo'),
            self::absolute(self::route('ecommerce.subscriptions.confirm')),
            'NOINDEX,NOFOLLOW',
            []
        );

        View::make(Ecommerce::viewPath('pages/subscriptions/confirm.php'), [
            'subscription' => $row,
            'state' => $state,
            'purchase' => $purchase ? SubscriptionTracking::purchase($row) : null,
            'account_url' => self::route('account.subscriptions'),
            'plans_url' => self::route('ecommerce.subscriptions.plans'),
        ])->render();
    }

    /**
     * I campi di fatturazione del modulo, come nel checkout: lo schema del
     * gestionale con i valori della scheda del cliente.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private static function billingFields(array $values): array
    {
        $schema = Subscription::billingAddress()->formSchema($values['billing_country'] ?? null);
        $labels = Subscription::billingAddress()->labels();
        $fields = [];

        foreach ($schema as $key => $field) {
            if (!is_object($field) || in_array($key, ['billing_phone_prefix', 'billing_phone'], true)) {
                continue;
            }

            if (isset($labels[$key]) && method_exists($field, 'label')) {
                $field->label((string) $labels[$key]);
            }

            if (method_exists($field, 'value') && array_key_exists($key, $values)) {
                $field->value((string) $values[$key]);
            }

            $fields[$key] = $field;
        }

        return $fields;
    }

    /** @param array<string, mixed> $contact @return array<string, string> */
    private static function contactValues(array $contact): array
    {
        $values = [];

        foreach (Subscription::billingAddress()->tableSchema() as $column) {
            $name = (string) $column->name;
            $values[$name] = trim((string) ($contact[substr($name, strlen('billing_'))] ?? ''));
        }

        $values['billing_country'] = $values['billing_country'] !== '' ? $values['billing_country'] : 'IT';

        return $values;
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
        http_response_code(404);
        exit;
    }
}
