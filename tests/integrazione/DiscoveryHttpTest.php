<?php
declare(strict_types=1);

require __DIR__.'/../harness.php';

$base = getenv('WI_TEST_URL') ?: 'https://ecommerce.test';
$request = static function (string $url): array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    $body = curl_exec($curl);
    if (!is_string($body)) throw new RuntimeException(curl_error($curl));
    return [curl_getinfo($curl, CURLINFO_HTTP_CODE), (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), $body];
};

[$status, $type, $llms] = $request($base.'/llms.txt');
check('llms.txt è Markdown con titolo, sommario e link assoluti', fn () =>
    $status === 200 && str_starts_with($type, 'text/plain')
    && preg_match('~^# .+\n\n> .+~', $llms) === 1
    && str_contains($llms, '('.$base.'/prodotti/)')
    && str_contains($llms, '('.$base.'/shared/catalogo.json)')
);

[$status, $type, $json] = $request($base.'/shared/catalogo.json?per_page=2');
$feed = json_decode($json, true) ?: [];
check('il feed JSON è paginato e i prodotti hanno URL e prezzo', fn () =>
    $status === 200 && str_starts_with($type, 'application/json')
    && $feed['per_page'] === 2 && count($feed['products']) <= 2
    && ($feed['total'] <= 2 || $feed['next'] === $base.'/shared/catalogo.json?page=2')
    && array_filter($feed['products'], static fn (array $p): bool => !str_starts_with($p['url'], $base.'/') || !is_numeric($p['price'])) === []
);

[$status, , $xml] = $request($base.'/shared/sitemap/catalogo.xml');
$sitemap = @simplexml_load_string($xml);
check('la sitemap del catalogo elenca le schede prodotto del feed', fn () =>
    $status === 200 && $sitemap !== false
    && in_array($feed['products'][0]['url'] ?? '-', array_map(static fn ($url): string => (string) $url->loc, iterator_to_array($sitemap->url, false)), true)
);

[$status, , $full] = $request($base.'/llms-full.txt');
check('llms-full.txt aggiunge i prodotti con il loro prezzo', fn () =>
    $status === 200 && str_starts_with($full, strtok($llms, "\n"))
    && str_contains($full, '### ['.($feed['products'][0]['name'] ?? '-').']('.($feed['products'][0]['url'] ?? '-').')')
);

[$status, , $html] = $request($feed['products'][0]['url'] ?? $base.'/');
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $ld);
$graph = json_decode($ld[1] ?? '', true)['@graph'] ?? [];
$types = array_column($graph, '@type');
$product = array_values(array_filter($graph, static fn ($e) => ($e['@type'] ?? '') === 'Product'))[0] ?? [];
check('la scheda prodotto pubblica Organization, WebSite e un venditore sulle offerte', fn () =>
    $status === 200
    && in_array('Organization', $types, true) && in_array('WebSite', $types, true)
    && ($product['offers']['seller']['@id'] ?? '') === $base.'/#organization'
);
check('la scheda prodotto ha og:type product e il prezzo Open Graph', fn () =>
    str_contains($html, '<meta property="og:type" content="product"')
    && preg_match('~<meta property="product:price:amount" content="\d+\.\d{2}"~', $html) === 1
);

summary();
