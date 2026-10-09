<?php

namespace Wonder\Plugin\Ecommerce;

use Wonder\App\Module\ConfigRepository;
use Wonder\App\Module\Contracts\ModuleInterface;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Ecommerce\Settings\OnlineShopSettings;
use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;
use Wonder\Plugin\Gestionale\Extensions\ProvidesSettings;
use Wonder\Plugin\Gestionale\Support\Orders\OrderEmail;
use Wonder\View\View;

/**
 * Entrypoint del modulo: percorsi, configurazione e layout del negozio; nelle
 * Impostazioni di Set Up porta il riquadro «Negozio online».
 *
 * Il modulo non porta header e footer: le sue pagine passano dai layout
 * sottili in `view/layout/frontend/`, che chainano su quelli del sito.
 */
final class Ecommerce implements ModuleInterface, ProvidesSettings, ProvidesOrderEmailExtras
{
    public const SLUG = 'ecommerce';

    private static ?array $config = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function manifestPath(): string
    {
        return self::root().'/module.json';
    }

    public static function handlerPath(string $path): string
    {
        return self::root().'/http/'.ltrim($path, '/');
    }

    /**
     * La view del sito, se pubblicata, vince su quella del modulo.
     *
     * Le view sigillate (carrello, checkout, area cliente, autenticazione) non
     * si pubblicano e qui l'override non va consultato: lo fa il piano 2 di
     * E1a, con l'elenco `views.sealed` del `module.json`.
     */
    public static function viewPath(string $path): string
    {
        $path = ltrim($path, '/');
        $root = (string) ($GLOBALS['ROOT'] ?? '');
        $custom = $root.'/custom/modules/'.self::SLUG.'/view/'.$path;

        foreach (self::sealedViews() as $sealed) {
            if ($path === $sealed || str_starts_with($path, rtrim($sealed, '/').'/')) {
                return self::root().'/view/'.$path;
            }
        }

        return $root !== '' && is_file($custom) ? $custom : self::root().'/view/'.$path;
    }

    public static function langPath(): string
    {
        return self::root().'/lang';
    }

    public static function settingsSections(): iterable
    {
        yield new OnlineShopSettings();
    }

    public static function assetPath(string $path = ''): string
    {
        return self::root().'/resources/assets/'.ltrim($path, '/');
    }

    /**
     * Apre un layout del negozio: mappa il nome corto sul file
     * `view/layout/frontend/ecommerce.<name>.php` (es. `layout('shop')`).
     *
     * I nomi sono `shop`, `checkout` e `auth`; ognuno chaina sul layout del
     * sito, che resta padrone di header e footer.
     */
    public static function layout(string $name, array $data = []): void
    {
        $name = ltrim($name, '/');

        if (str_ends_with($name, '.php')) {
            $name = substr($name, 0, -4);
        }

        View::layout(self::viewPath('layout/frontend/ecommerce.'.$name.'.php'), $data);
    }

    /** Configurazione del modulo, con l'override del sito. */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            $defaults = require self::root().'/config/module.php';
            $site = class_exists(ConfigRepository::class) ? ConfigRepository::for(self::SLUG) : [];
            self::$config = array_replace_recursive((array) $defaults, (array) $site);
        }

        if ($key === null) {
            return self::$config;
        }

        $value = self::$config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** Solo per i test: rilegge la configurazione. */
    public static function forgetConfig(): void
    {
        self::$config = null;
    }

    /** @return list<string> */
    public static function sealedViews(): array
    {
        $manifest = json_decode((string) file_get_contents(self::manifestPath()), true);

        return array_values(array_filter(
            (array) ($manifest['views']['sealed'] ?? []),
            static fn (mixed $path): bool => is_string($path) && trim($path) !== ''
        ));
    }

    /**
     * Nelle email al cliente con un account, il link all'ordine nella sua area
     * riservata. Nella conferma di un ordine fatto da ospite anche il link per
     * scegliere la password: online la conferma arriva col pagamento, senza la
     * sessione del checkout.
     */
    public static function orderEmailExtras(string $key, array $order): array
    {
        $userId = (int) ($order['user_id'] ?? 0);
        $code = trim((string) ($order['code'] ?? ''));
        if ($userId <= 0 || in_array($key, OrderEmail::MERCHANT_KEYS, true)) {
            return [];
        }

        $extras = [];
        if ($code !== '') {
            try {
                $extras['order_url'] = Route::url('account.orders.show', ['code' => $code]);
            } catch (\Throwable) {
                // Senza la rotta dell'account l'email parte lo stesso, senza il bottone.
            }
        }

        $link = $key === 'confirmed' ? GuestCheckout::passwordLink($userId, \__r('ecommerce.auth.password.restore')) : '';

        return $link === '' ? $extras : $extras + ['account_url' => $link];
    }
}
