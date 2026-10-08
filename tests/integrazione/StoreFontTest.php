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
use Wonder\Plugin\Gestionale\Models\System\Setting;

$righe = [
    ['id' => 3, 'name' => 'Inter', 'font_family' => '"Inter", sans-serif'],
    ['id' => 12, 'name' => 'Marchio', 'font_family' => "\\'Marchio Sans\\', serif"],
];

check('il font scelto per l\'area cambia le variabili del sito, senza caricarlo una seconda volta', function () use ($righe): bool {
    $css = StoreFont::style('checkout', ['font_checkout' => 'Inter'], $righe);

    return str_starts_with($css, '<style>html:root{') && str_ends_with($css, '}</style>')
        && str_contains($css, '"Inter", sans-serif')
        && !str_contains($css, '@font-face');
});

check('il nome si riconosce senza badare alle maiuscole e la famiglia si ripulisce', fn () =>
    str_contains(StoreFont::style('cart', ['font_cart' => 'MARCHIO'], $righe), '"Marchio Sans", serif')
);

check('area sconosciuta, font che non è in css_font o vuoto: niente', fn () =>
    StoreFont::style('blog', ['font_blog' => 'Inter'], $righe) === ''
    && StoreFont::style('checkout', ['font_checkout' => 'Comic Sans'], $righe) === ''
    && StoreFont::style('cart', ['font_cart' => ''], $righe) === ''
);

$riga = Setting::current();

check('senza argomenti legge le Impostazioni di Set Up e la tabella css_font', function () use ($riga): bool {
    if ($riga === [] || !array_key_exists('font_cart', $riga)) {
        return false;
    }

    $prima = (string) $riga['font_cart'];
    Setting::update(['font_cart' => 'Inter'], 1);
    $css = StoreFont::style('cart');
    Setting::update(['font_cart' => $prima], 1);

    return str_contains($css, 'Inter');
});

summary();
