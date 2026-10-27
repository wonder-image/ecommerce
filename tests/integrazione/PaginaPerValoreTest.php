<?php
/** php tests/integrazione/PaginaPerValoreTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCatalog;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

final class AnnullaPaginaPerValore extends RuntimeException {}

/** Niente feed e sitemap rifatti dal test. */
final class ProvaPagineEcommerce extends ProductModelResource
{
    protected static function refreshCatalog(): void {}
}

// Una pagina per valore scelta nell'articolo (gestionale #85): i 301 fra
// pagina e query, e il filtro che trova il valore in tutte e due le tabelle.
try {
    Transaction::run(static function (): void {
        $attributo = static function (string $name, string $level, array $labels): array {
            $id = (int) (Attribute::create([
                'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
                'name' => $name,
                'slug' => Slug::make($name.'-'.uniqid()),
                'type' => 'select',
                'level' => $level,
                'unit' => '',
                'group_name' => '',
                'is_filterable' => 'true',
                'is_visible' => 'true',
                'position' => 90,
            ])->insert_id ?? 0);
            $values = [];

            foreach ($labels as $position => $label) {
                $values[] = (int) (AttributeValue::create(['attribute_id' => $id, 'label' => $label, 'color' => '', 'position' => $position + 1])->insert_id ?? 0);
            }

            return ['id' => $id, 'slug' => (string) (Attribute::find(['id' => $id], 1)['slug'] ?? ''), 'values' => $values];
        };

        $colore = $attributo('Prova ppv colore', 'variant', ['Blu', 'Rosso']);
        $taglia = $attributo('Prova ppv taglia', 'product', ['S', 'M']);
        ProductModelResource::forgetCatalogCache();

        $id = (int) (ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova ppv',
            'slug' => Slug::make('prova-ppv-'.uniqid()),
            'sku' => 'PPV-E',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ])->insert_id ?? 0);
        Skeleton::forModel($id, 'Prova ppv', 'PPV-E');
        $slug = (string) ProductModel::find(['id' => $id], 1)['slug'];

        $salva = static fn (string $pagina) => ProvaPagineEcommerce::saveExtras($id, [
            'has_variants' => 'true',
            'option_'.$colore['id'] => array_map('strval', $colore['values']),
            'option_'.$taglia['id'] => array_map('strval', $taglia['values']),
            'product_price' => '10,00',
            'page_attribute' => $pagina,
        ], 'PPV');

        $trovato = static function (string $chiave, string $valore) use ($id): bool {
            $where = CatalogFilter::fromRequest('all', [], [$chiave => $valore])->where();
            $row = $GLOBALS['MYSQLI_CONNECTION']['main']->query("SELECT `id` FROM `gst_product_models` {$where} AND `gst_product_models`.`id` = {$id}");

            return $row !== false && $row->num_rows === 1;
        };

        $salva((string) $colore['id']);
        $pagine = ProductVariant::find(['product_model_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC');
        $blu = (string) ($pagine[0]['slug'] ?? '');

        check('acceso: ?colore=blu va sulla pagina blu e tiene il resto della query', fn () => str_ends_with(
            ProductCatalog::resolve($slug, '', [$colore['slug'] => 'blu', $taglia['slug'] => 's'])['redirect'] ?? '',
            '/prodotto/'.$slug.'/'.$blu.'/?'.$taglia['slug'].'=s'
        ));

        check('acceso: senza query la scheda si apre senza rimandi', fn () =>
            ProductCatalog::resolve($slug)['redirect'] === null
        );

        check('acceso: il filtro trova il colore sulla tabella delle varianti', fn () =>
            $trovato($colore['slug'], 'blu') && $trovato($colore['slug'], 'rosso')
        );

        $salva('');

        check('spento: la vecchia pagina va sulla query del colore', fn () => str_ends_with(
            ProductCatalog::resolve($slug, $blu)['redirect'] ?? '',
            '/prodotto/'.$slug.'/?'.$colore['slug'].'=blu'
        ));

        check('spento: la query non rimanda a una pagina, niente giri', fn () =>
            ProductCatalog::resolve($slug, '', [$colore['slug'] => 'blu'])['redirect'] === null
        );

        check('spento: il filtro trova il colore sulla tabella delle opzioni', fn () =>
            $trovato($colore['slug'], 'blu') && $trovato($taglia['slug'], 's')
        );

        throw new AnnullaPaginaPerValore();
    });
} catch (AnnullaPaginaPerValore) {
}

summary();
