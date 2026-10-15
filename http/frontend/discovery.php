<?php

use Wonder\Plugin\Ecommerce\Frontend\Discovery\CatalogFeed;
use Wonder\Plugin\Ecommerce\Frontend\Discovery\CatalogSitemap;
use Wonder\Plugin\Ecommerce\Frontend\Discovery\LlmsTxt;

// File per motori e assistenti AI: solo dati pubblici del catalogo.
$action = (string) ($ROUTE_META['discovery_action'] ?? '');

[$type, $body] = match ($action) {
    'llms' => ['text/plain; charset=UTF-8', LlmsTxt::index()],
    'llms-full' => ['text/plain; charset=UTF-8', LlmsTxt::full()],
    'sitemap' => ['application/xml; charset=UTF-8', CatalogSitemap::xml()],
    'feed' => ['application/json; charset=UTF-8', (string) json_encode(
        CatalogFeed::page((int) ($_GET['page'] ?? 1), (int) ($_GET['per_page'] ?? CatalogFeed::PER_PAGE)),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    )],
    default => [null, null],
};

if ($type === null) {
    http_response_code(404);
    return;
}

header('Content-Type: '.$type);
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo $body;
exit;
