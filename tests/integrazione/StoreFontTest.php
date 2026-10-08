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
use Wonder\Plugin\Ecommerce\Support\ShopFonts;
use Wonder\Plugin\Gestionale\Models\System\Setting;

$righe = [
    ['id' => 3, 'name' => 'Inter', 'font_family' => '"Inter", sans-serif'],
    ['id' => 12, 'name' => 'Marchio', 'font_family' => "\\'Marchio Sans\\', serif"],
];

check('il font scelto per l\'area cambia le variabili del sito, senza caricarlo una seconda volta', function () use ($righe): bool {
    $css = StoreFont::style('checkout', ['font_checkout_id' => 3], $righe);

    return str_starts_with($css, '<style>html:root{') && str_ends_with($css, '}</style>')
        && str_contains($css, '"Inter", sans-serif')
        && !str_contains($css, '@font-face');
});

check('l\'id si riconosce anche scritto come testo e la famiglia si ripulisce', fn () =>
    str_contains(StoreFont::style('cart', ['font_cart_id' => '12'], $righe), '"Marchio Sans", serif')
);

check('area sconosciuta, id che non è in css_font, un nome o vuoto: niente', fn () =>
    StoreFont::style('blog', ['font_blog_id' => 3], $righe) === ''
    && StoreFont::style('checkout', ['font_checkout_id' => 42], $righe) === ''
    && StoreFont::style('checkout', ['font_checkout_id' => 'Inter'], $righe) === ''
    && StoreFont::style('cart', ['font_cart_id' => null], $righe) === ''
);

check('i font cancellati non si scelgono', function (): bool {
    $id = (int) sqlInsert('css_font', [
        'name' => 'Cancellato di prova', 'slug' => 'cancellato-di-prova',
        'font_family' => '"Cancellato", serif', 'visible' => 'true', 'deleted' => 'true',
    ])->insert_id;
    $ids = array_map('intval', array_column(ShopFonts::visible(), 'id'));
    sqlDelete('css_font', ['id' => $id]);

    return $id > 0 && !in_array($id, $ids, true);
});

$riga = Setting::current();
$inter = (int) (sqlSelect('css_font', ['name' => 'Inter', 'deleted' => 'false'], 1)->row['id'] ?? 0);

check('senza argomenti legge le Impostazioni di Set Up e la tabella css_font', function () use ($riga, $inter): bool {
    if ($riga === [] || !array_key_exists('font_cart_id', $riga) || $inter <= 0) {
        return false;
    }

    $prima = (string) ($riga['font_cart_id'] ?? '');
    Setting::update(['font_cart_id' => (string) $inter], 1);
    $css = StoreFont::style('cart');
    Setting::update(['font_cart_id' => $prima], 1);

    return str_contains($css, 'Inter');
});

summary();
