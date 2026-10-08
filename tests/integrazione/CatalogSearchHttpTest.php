<?php
declare(strict_types=1);

require __DIR__.'/../harness.php';

$request = static function (string $path, ?array $post = null): array {
    $curl = curl_init((getenv('WI_TEST_URL') ?: 'https://ecommerce.test').$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($curl);
    if (!is_string($body)) throw new RuntimeException(curl_error($curl));
    return [curl_getinfo($curl, CURLINFO_HTTP_CODE), $body];
};

foreach (['/prodotti/' => 'Tutti i prodotti', '/prodotti/?ordina=nome_asc' => 'Tutti i prodotti',
    '/prodotti/?prezzo_a=30' => 'Prodotti filtrati', '/cerca/?q=maglietta' => 'Prodotti filtrati'] as $path => $title) {
    [$status, $html] = $request($path);
    check('titolo e schema coerenti su '.$path, function () use ($status, $html, $title) {
        preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $h1);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $json);
        $schema = json_decode($json[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $itemList = array_values(array_filter($schema['@graph'] ?? [], static fn ($entity) => ($entity['@type'] ?? '') === 'ItemList'))[0] ?? [];
        return $status === 200 && ($h1[1] ?? '') === $title && ($itemList['name'] ?? '') === $title
            && str_contains($html, 'data-wi-search-url="'.(getenv('WI_TEST_URL') ?: 'https://ecommerce.test').'/api/ecommerce/catalog/products/search/"');
    });
}

foreach (['', 'maglietta', '<script>', ['malformed']] as $term) {
    [$status, $body] = $request('/api/ecommerce/catalog/products/search/', ['search' => $term]);
    check('suggerimenti pubblici validi per '.json_encode($term), function () use ($status, $body, $term) {
        $results = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if ($status !== 200 || !is_array($results) || count($results) > 8) return false;
        if ($term === '' || is_array($term)) return $results === [];
        foreach ($results as $result) {
            if (!isset($result['value'], $result['label'], $result['input-value']) || str_contains($result['label'], '<script>')) return false;
        }
        return true;
    });
}

summary();
