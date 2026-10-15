<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Discovery;

/**
 * `/llms.txt` e `/llms-full.txt` secondo la proposta llmstxt.org: un indice
 * Markdown del negozio per gli assistenti AI, e la sua versione estesa con
 * l'elenco dei prodotti (limitato, il resto sta nel feed JSON).
 */
final class LlmsTxt
{
    public const FULL_LIMIT = 2000;

    public static function index(): string
    {
        return implode("\n", self::head())."\n";
    }

    public static function full(int $limit = self::FULL_LIMIT): string
    {
        $lines = self::head();
        $lines[] = '';
        $lines[] = '## '.__t('discovery.llms.products');

        $count = 0;
        foreach (CatalogFeed::all($limit + 1) as $product) {
            if (++$count > $limit) {
                $lines[] = '';
                $lines[] = (string) __t('discovery.llms.truncated', ['count' => $limit]);
                break;
            }
            array_push($lines, '', ...self::product($product));
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private static function head(): array
    {
        $site = CatalogFeed::site();
        $name = CatalogFeed::siteName() ?: (string) parse_url($site, PHP_URL_HOST);

        $lines = [
            '# '.self::inline($name),
            '',
            '> '.self::inline((string) __t('discovery.llms.summary', ['site' => $name, 'currency' => CatalogFeed::currency()])),
            '',
            (string) __t('discovery.llms.intro'),
            '',
            '## '.__t('discovery.llms.catalog'),
            self::link((string) __t('discovery.llms.catalog_all'), __r('ecommerce.catalog.index')),
            self::link((string) __t('discovery.llms.catalog_offers'), __r('ecommerce.catalog.offers')),
            self::link((string) __t('discovery.llms.catalog_new'), __r('ecommerce.catalog.new')),
        ];

        $categories = CatalogFeed::categories();
        if ($categories !== []) {
            $lines[] = '';
            $lines[] = '## '.__t('discovery.llms.categories');
            foreach ($categories as $category) {
                $lines[] = str_repeat('  ', $category['depth'] - 1)
                    .self::link($category['name'], $category['url'], self::excerpt($category['description']));
            }
        }

        $lines[] = '';
        $lines[] = '## '.__t('discovery.llms.data');
        $lines[] = self::link((string) __t('discovery.llms.data_feed'), $site.CatalogFeed::PATH, (string) __t('discovery.llms.data_feed_note', ['per_page' => CatalogFeed::PER_PAGE]));
        $lines[] = self::link((string) __t('discovery.llms.data_sitemap'), $site.CatalogSitemap::PATH);
        $lines[] = self::link((string) __t('discovery.llms.data_full'), $site.'/llms-full.txt');

        return $lines;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string>
     */
    private static function product(array $product): array
    {
        $money = static fn (float $value): string => number_format($value, 2, '.', '').' '.$product['currency'];
        $lines = ['### ['.self::inline((string) $product['name']).']('.$product['url'].')'];
        if ($product['description'] !== '') {
            $lines[] = self::inline((string) $product['description']);
        }
        $lines[] = isset($product['regular_price'])
            ? (string) __t('discovery.llms.price_sale', ['price' => $money((float) $product['price']), 'regular' => $money((float) $product['regular_price'])])
            : (string) __t('discovery.llms.price', ['price' => $money((float) $product['price'])]);
        if (!empty($product['variants'])) {
            $lines[] = (string) __t('discovery.llms.variants', ['list' => implode(', ', array_map(
                static fn (array $variant): string => '['.self::inline($variant['name']).']('.$variant['url'].')',
                $product['variants']
            ))]);
        }

        return $lines;
    }

    private static function link(string $title, string $url, string $note = ''): string
    {
        return '- ['.self::inline($title).']('.$url.')'.($note !== '' ? ': '.self::inline($note) : '');
    }

    private static function excerpt(string $text, int $length = 160): string
    {
        $text = self::inline($text);

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }

    /** Una riga sola, senza caratteri che rompono i link Markdown. */
    private static function inline(string $text): string
    {
        return trim(str_replace(['[', ']'], ['(', ')'], preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
