<?php

namespace Wonder\Plugin\Ecommerce\Frontend;

use Throwable;
use Wonder\Plugin\Ecommerce\Support\ShopFonts;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\View\WebFonts;

/**
 * Il font scelto nelle Impostazioni di Set Up per accesso, account, checkout
 * e carrello: cambia solo le variabili del sito, perché la testa carica già
 * ogni font visibile di `css_font`.
 */
final class StoreFont
{
    public const AREAS = ['auth', 'account', 'checkout', 'cart'];

    /**
     * @param array<string, mixed>|null $settings le Impostazioni di Set Up (null: quelle salvate)
     * @param list<array<string, mixed>>|null $fonts le righe di `css_font` (null: quelle del sito)
     */
    public static function style(string $area, ?array $settings = null, ?array $fonts = null): string
    {
        if (!in_array($area, self::AREAS, true)) {
            return '';
        }

        if ($settings === null) {
            try {
                $settings = Setting::current();
            } catch (Throwable) {
                return '';
            }
        }

        $row = ShopFonts::find((string) ($settings['font_'.$area] ?? ''), $fonts ?? ShopFonts::visible());
        $css = $row === null ? '' : WebFonts::variables((string) ($row['font_family'] ?? ''));

        return $css === '' ? '' : '<style>'.$css.'</style>';
    }
}
