<?php

namespace Wonder\Plugin\Ecommerce\Frontend;

use Throwable;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\View\WebFonts;

/** Il font che il commerciante ha scelto per accesso, account, checkout e carrello. */
final class StoreFont
{
    public const AREAS = ['auth', 'account', 'checkout', 'cart'];

    /** @param array<string, mixed>|null $settings le impostazioni del commerciante (null: quelle salvate) */
    public static function style(string $area, ?array $settings = null): string
    {
        if (!in_array($area, self::AREAS, true)) {
            return '';
        }

        if ($settings === null) {
            try {
                $settings = MerchantSetting::current();
            } catch (Throwable) {
                return '';
            }
        }

        $css = WebFonts::css((string) ($settings['font_'.$area] ?? ''));

        return $css === '' ? '' : '<style>'.$css.'</style>';
    }
}
