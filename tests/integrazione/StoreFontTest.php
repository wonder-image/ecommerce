<?php
/** php tests/integrazione/StoreFontTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\StoreFont;

check('il font scelto per l\'area diventa uno <style> con le variabili del sito', function (): bool {
    $css = StoreFont::style('checkout', ['font_checkout' => 'inter']);

    return str_starts_with($css, '<style>') && str_ends_with($css, '</style>')
        && str_contains($css, '@font-face') && str_contains($css, 'Inter');
});

check('area sconosciuta, font sconosciuto o vuoto: niente', fn () =>
    StoreFont::style('blog', ['font_blog' => 'inter']) === ''
    && StoreFont::style('checkout', ['font_checkout' => 'comic']) === ''
    && StoreFont::style('cart', ['font_cart' => '']) === ''
);

check('senza impostazioni legge quelle del commerciante', fn () =>
    is_string(StoreFont::style('checkout'))
);

summary();
