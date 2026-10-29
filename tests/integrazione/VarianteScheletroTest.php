<?php
/** php tests/integrazione/VarianteScheletroTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
$loader = require SITE.'/vendor/autoload.php';
// Il modulo sotto prova è questo, non la copia installata nel sito.
$loader->setPsr4('Wonder\\Plugin\\Ecommerce\\', __DIR__.'/../../src');
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCatalog;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

final class AnnullaVarianteScheletro extends RuntimeException {}

/** Niente feed e sitemap rifatti dal test. */
final class ProvaScheletroEcommerce extends ProductModelResource
{
    protected static function refreshCatalog(): void {}
}

// «Una pagina per ogni valore» spento: la variante è lo scheletro e porta il
// nome del modello. Il bottone deve dire il formato, non ripetere il titolo.
try {
    Transaction::run(static function (): void {
        $formato = (int) (Attribute::create([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => 'Prova scheletro formato',
            'slug' => Slug::make('prova-scheletro-formato-'.uniqid()),
            'type' => 'button',
            'level' => 'product',
            'unit' => '',
            'group_name' => '',
            'is_filterable' => 'true',
            'is_visible' => 'true',
            'position' => 90,
        ])->insert_id ?? 0);
        $valori = [];
        foreach (['30ml', '150ml'] as $position => $label) {
            $valori[] = (string) (AttributeValue::create(['attribute_id' => $formato, 'label' => $label, 'color' => '', 'position' => $position + 1])->insert_id ?? 0);
        }
        ProductModelResource::forgetCatalogCache();

        // Come su illuna.it: «Formati» è di livello variante ma, senza una
        // pagina per valore, finisce sull'opzione.
        $formatoVariante = (int) (Attribute::create([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => 'Prova scheletro formato variante',
            'slug' => Slug::make('prova-scheletro-formato-variante-'.uniqid()),
            'type' => 'button',
            'level' => 'variant',
            'unit' => '',
            'group_name' => '',
            'is_filterable' => 'true',
            'is_visible' => 'true',
            'position' => 91,
        ])->insert_id ?? 0);
        $valoreVariante = (string) (AttributeValue::create(['attribute_id' => $formatoVariante, 'label' => '50ml', 'color' => '', 'position' => 1])->insert_id ?? 0);
        ProductModelResource::forgetCatalogCache();

        $modello = static function (array $scelti, ?int $attributo = null) use ($formato): array {
            $attributo ??= $formato;
            $id = (int) (ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova scheletro',
                'slug' => Slug::make('prova-scheletro-'.uniqid()),
                'sku' => 'SCH-'.uniqid(),
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ])->insert_id ?? 0);
            Skeleton::forModel($id, 'Prova scheletro', 'SCH');
            ProvaScheletroEcommerce::saveExtras($id, [
                'has_variants' => 'true',
                'option_'.$attributo => $scelti,
                'product_price' => '30,00',
                'page_attribute' => '',
            ], 'SCH');

            $detail = ProductCatalog::find((string) ProductModel::find(['id' => $id], 1)['slug']);

            return $detail !== null ? $detail->data() : [];
        };

        $uno = $modello([$valori[1]]);
        check('un solo formato: il bottone dice «150ml», non il nome del modello', fn () =>
            array_column($uno['variants'] ?? [], 'name') === ['150ml']
            && ($uno['variants'][0]['active'] ?? false) === true
        );

        $due = $modello($valori);
        check('più formati: niente bottone col nome del modello, sceglie il selettore', fn () =>
            count($due['offers'] ?? []) === 2
            && ($due['variants'] ?? null) === []
        );

        $variante = $modello([$valoreVariante], $formatoVariante);
        check('attributo di livello variante sull\'opzione: il bottone dice «50ml»', fn () =>
            array_column($variante['variants'] ?? [], 'name') === ['50ml']
        );

        throw new AnnullaVarianteScheletro();
    });
} catch (AnnullaVarianteScheletro) {
}

summary();
