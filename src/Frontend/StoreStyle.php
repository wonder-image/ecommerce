<?php

namespace Wonder\Plugin\Ecommerce\Frontend;

/** Le misure dei testi che accesso, account, checkout e carrello condividono. */
final class StoreStyle
{
    public static function sheet(): string
    {
        $href = module_asset('ecommerce', 'css/store.css');

        return $href === '' ? '' : '<link rel="stylesheet" href="'.e($href).'">';
    }
}
