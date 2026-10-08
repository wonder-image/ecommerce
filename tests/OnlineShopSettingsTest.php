<?php
/** php tests/OnlineShopSettingsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Settings\OnlineShopSettings;
use Wonder\Plugin\Gestionale\Extensions\ProvidesSettings;
use Wonder\Plugin\Gestionale\Extensions\SettingsSections;

/** Le righe visibili di `css_font`, come le legge il sito. */
$righe = [
    ['id' => 1, 'name' => 'Roboto', 'font_family' => '"Roboto", sans-serif'],
    ['id' => 3, 'name' => 'Inter', 'font_family' => '"Inter", sans-serif'],
    ['id' => 9, 'name' => 'Open Sans', 'font_family' => '"Open Sans", sans-serif'],
];
$riquadro = new OnlineShopSettings($righe);

$perNome = static function (iterable $cose): array {
    $mappa = [];
    foreach ($cose as $cosa) {
        $mappa[(string) ($cosa->name ?? $cosa->key)] = $cosa;
    }

    return $mappa;
};

check('il modulo porta il riquadro «Negozio online» nelle Impostazioni di Set Up', function () {
    $riquadri = SettingsSections::fromEntrypoints([Ecommerce::class]);

    return is_subclass_of(Ecommerce::class, ProvidesSettings::class)
        && count($riquadri) === 1 && $riquadri[0] instanceof OnlineShopSettings;
});

check('le colonne: quattro font, il checkout parte da Inter, gli ordini senza account spenti', function () use ($riquadro, $perNome) {
    $colonne = $perNome($riquadro->columns());
    $dati = $perNome($riquadro->data());
    $chiavi = ['font_auth', 'font_account', 'font_checkout', 'font_cart', 'checkout_guest'];

    return array_keys($colonne) === array_keys($dati)
        && array_diff($chiavi, array_keys($colonne)) === [] && count($colonne) === 5
        && (string) $colonne['font_checkout']->getSchema('default') === 'Inter'
        && (string) $colonne['checkout_guest']->getSchema('default') === 'false'
        && (string) ($colonne['font_auth']->getSchema('default') ?? '') === '';
});

check('i font si scelgono fra quelli della tabella css_font, più «Come il sito»', function () use ($riquadro, $perNome) {
    $campi = $perNome($riquadro->fields());
    $attese = ['' => 'Come il sito', 'Roboto' => 'Roboto', 'Inter' => 'Inter', 'Open Sans' => 'Open Sans'];

    foreach (['font_auth', 'font_account', 'font_checkout', 'font_cart'] as $chiave) {
        if (!isset($campi[$chiave]) || (array) $campi[$chiave]->get('options') !== $attese) {
            return false;
        }
    }

    return isset($campi['checkout_guest']);
});

check('ogni campo ha la sua etichetta', fn () =>
    $riquadro->labels() === [
        'font_auth' => 'Font accesso',
        'font_account' => 'Font account',
        'font_checkout' => 'Font checkout',
        'font_cart' => 'Font carrello',
        'checkout_guest' => 'Ordini senza account',
    ]
);

check('il riquadro si chiama «Negozio online» e porta anche gli ordini senza account', function () use ($riquadro, $righe) {
    $chieste = [];
    $card = $riquadro->card(static function (string $chiave) use (&$chieste, $righe): object {
        $chieste[] = $chiave;

        return SectionTitle::make($chiave);
    });
    $titoli = array_map(
        static fn ($pezzo): string => $pezzo instanceof SectionTitle ? $pezzo->getText() : '',
        $card->components
    );

    return $titoli[0] === 'Negozio online'
        && in_array('Ordini senza account', $titoli, true)
        && $chieste === ['font_auth', 'font_account', 'font_checkout', 'font_cart', 'checkout_guest'];
});

check('un font si salva col nome della sua riga; uno che non c\'è torna «Come il sito»', function () use ($riquadro) {
    $valori = $riquadro->mutate([
        'font_auth' => ' inter ',
        'font_account' => 'OPEN SANS',
        'font_checkout' => 'Comic Sans',
        'font_cart' => '',
        'tax_regime' => 'RF01',
    ]);

    return $valori === [
        'font_auth' => 'Inter',
        'font_account' => 'Open Sans',
        'font_checkout' => '',
        'font_cart' => '',
        'tax_regime' => 'RF01',
    ];
});

check('gli ordini senza account si salvano accesi o spenti, nient\'altro', fn () =>
    $riquadro->mutate(['checkout_guest' => 'true'])['checkout_guest'] === 'true'
    && $riquadro->mutate(['checkout_guest' => 'sì'])['checkout_guest'] === 'false'
    && !array_key_exists('checkout_guest', $riquadro->mutate(['tax_regime' => 'RF01']))
);

check('senza il database del sito la scelta resta solo «Come il sito»', function () use ($perNome) {
    $campi = $perNome((new OnlineShopSettings())->fields());

    return (array) $campi['font_cart']->get('options') === ['' => 'Come il sito']
        && (new OnlineShopSettings())->mutate(['font_cart' => 'Inter'])['font_cart'] === '';
});

summary();
