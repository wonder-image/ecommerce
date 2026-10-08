<?php

use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductListing;

$term = $_POST['search'] ?? '';
$term = is_scalar($term) ? mb_substr(trim((string) $term), 0, 120) : '';
$results = [];
if ($term !== '') {
    $filter = CatalogFilter::fromRequest('search', [], ['q' => $term]);
    foreach (ProductListing::cards($filter, '0,8') as $product) {
        $name = (string) $product['name'];
        // La lib compone i suggerimenti come HTML e attributi single-quoted.
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $results[] = ['value' => $safeName, 'label' => $safeName, 'input-value' => $safeName];
    }
}

// searchText() della lib decodifica esplicitamente il testo con JSON.parse.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
