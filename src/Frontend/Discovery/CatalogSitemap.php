<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Discovery;

/**
 * `/shared/sitemap/catalogo.xml`, accanto alla sitemap del sito: pagine del catalogo, categorie e schede prodotto
 * generate dal database, così non dipendono dal crawler della sitemap del
 * sito. robots.txt la elenca tramite `seo.sitemaps` di module.json.
 */
final class CatalogSitemap
{
    public const PATH = '/shared/sitemap/catalogo.xml';

    /** Limite di URL per file del protocollo sitemaps.org. */
    public const MAX_URLS = 50000;

    public static function xml(): string
    {
        $urls = [
            __r('ecommerce.catalog.index'),
            __r('ecommerce.catalog.offers'),
            __r('ecommerce.catalog.new'),
        ];
        foreach (CatalogFeed::categories() as $category) {
            $urls[] = $category['url'];
        }
        foreach (CatalogFeed::all(self::MAX_URLS) as $product) {
            $urls[] = $product['url'];
            foreach ($product['variants'] ?? [] as $variant) {
                $urls[] = $variant['url'];
            }
        }

        return self::render(array_slice(array_values(array_unique(array_filter($urls))), 0, self::MAX_URLS));
    }

    /** @param list<string> $urls */
    public static function render(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
