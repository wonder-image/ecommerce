<?php
/** php tests/integrazione/ProductUrlHttpTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCatalog;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

$request = static function (string $path): array {
    $curl = curl_init('https://ecommerce.test'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    preg_match('~^location:\s*(\S+)~mi', substr($response, 0, $size), $location);
    return [(int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $location[1] ?? '', substr($response, $size)];
};
$canonical = static function (string $html): string {
    preg_match('~<link rel="canonical" href="([^"]*)"~', $html, $match);
    return html_entity_decode($match[1] ?? '');
};
$rows = static fn (mixed $rows): array => !is_array($rows) || $rows === []
    ? []
    : (array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows]);

// Dal catalogo di prova: un modello con più varianti visibili con slug e
// opzioni, e uno con una variante sola.
$conPiu = null;
$conUna = null;
foreach ($rows(ProductModel::find(['visible' => 'true', 'visible_online' => 'true', 'deleted' => 'false'])) as $modello) {
    $slug = (string) ($modello['slug'] ?? '');
    $varianti = $rows(ProductVariant::find(
        ['product_model_id' => (int) $modello['id'], 'visible' => 'true', 'deleted' => 'false'], null, 'position', 'ASC'
    ));
    $scheda = $slug !== '' ? ProductCatalog::find($slug) : null;
    if ($scheda === null) continue;

    if ($conPiu === null && count($varianti) > 1 && ($varianti[0]['slug'] ?? '') !== '' && ($varianti[1]['slug'] ?? '') !== ''
        && count($scheda->data()['offers']) > 1 && $scheda->data()['option_groups'] !== []) {
        $conPiu = ['slug' => $slug, 'prima' => (string) $varianti[0]['slug'], 'seconda' => (string) $varianti[1]['slug']];
    }
    if ($conUna === null && count($varianti) === 1) {
        $conUna = ['slug' => $slug, 'variante' => (string) ($varianti[0]['slug'] ?? '') ?: 'variante'];
    }
}

if ($conPiu === null || $conUna === null) {
    check('il catalogo di prova ha i modelli che servono (lancia gestionale:demo e gestionale:variant-slugs)', fn () => false);
    summary();
}

$base = 'https://ecommerce.test/prodotto/';

check('il modello con più varianti mostra la prima e la dichiara canonical', function () use ($request, $canonical, $conPiu, $base) {
    [$status, , $html] = $request('/prodotto/'.$conPiu['slug'].'/');
    return $status === 200 && $canonical($html) === $base.$conPiu['slug'].'/'.$conPiu['prima'].'/';
});

check('ogni variante è canonical di sé stessa, e la query non entra nel canonical', function () use ($request, $canonical, $conPiu, $base) {
    [$status, , $html] = $request('/prodotto/'.$conPiu['slug'].'/'.$conPiu['seconda'].'/');
    [$conQuery, , $htmlQuery] = $request('/prodotto/'.$conPiu['slug'].'/'.$conPiu['seconda'].'/?taglia=zz&x=1');
    $atteso = $base.$conPiu['slug'].'/'.$conPiu['seconda'].'/';
    return $status === 200 && $canonical($html) === $atteso && $conQuery === 200 && $canonical($htmlQuery) === $atteso;
});

check('il modello con una variante sola ha un solo indirizzo', function () use ($request, $conUna) {
    [$status, $location] = $request('/prodotto/'.$conUna['slug'].'/'.$conUna['variante'].'/');
    return $status === 301 && str_ends_with($location, '/prodotto/'.$conUna['slug'].'/');
});

check('la variante sconosciuta rimanda al modello e tiene la query', function () use ($request, $conPiu) {
    [$status, $location] = $request('/prodotto/'.$conPiu['slug'].'/non-esiste/?utm_source=prova');
    return $status === 301 && str_ends_with($location, '/prodotto/'.$conPiu['slug'].'/?utm_source=prova');
});

check('il modello sconosciuto è un 404, con o senza variante', function () use ($request) {
    return $request('/prodotto/non-esiste-davvero/')[0] === 404
        && $request('/prodotto/non-esiste-davvero/blu/')[0] === 404;
});

check('la query sceglie l\'opzione', function () use ($conPiu) {
    $data = ProductCatalog::find($conPiu['slug'])->data();
    $offerta = $data['offers'][array_key_last($data['offers'])];
    $query = [];
    foreach ($data['option_groups'] as $gruppo) {
        foreach ($gruppo['values'] as $valore) {
            if ((string) $valore['id'] === (string) ($offerta['attributes'][(string) $gruppo['id']] ?? '')) {
                $query[$gruppo['slug']] = $valore['slug'];
            }
        }
    }
    $scelta = ProductCatalog::resolve($conPiu['slug'], '', $query)['detail']->data()['selected_product_id'];
    return $query !== [] && $scelta === $offerta['product_id'];
});

check('cambiando colore le opzioni compatibili restano selezionate', function () use ($conPiu) {
    $first = ProductCatalog::resolve($conPiu['slug'], $conPiu['prima'])['detail']->data();
    $tested = 0;
    foreach ($first['offers'] as $offer) {
        $query = [];
        foreach ($first['option_groups'] as $group) {
            foreach ($group['values'] as $value) {
                if ((string) $value['id'] === (string) ($offer['attributes'][(string) $group['id']] ?? '')) {
                    $query[$group['slug']] = $value['slug'];
                }
            }
        }
        if ($query === []) continue;
        $other = ProductCatalog::resolve($conPiu['slug'], $conPiu['seconda'], $query)['detail']->data();
        foreach ($other['offers'] as $candidate) {
            if ($candidate['attributes'] !== $offer['attributes']) continue;
            $selected = array_values(array_filter($other['offers'], static fn ($item) => $item['product_id'] === $other['selected_product_id']))[0];
            if ($selected['attributes'] !== $offer['attributes']) return false;
            $tested++;
            break;
        }
    }
    return $tested > 0;
});

summary();
