<?php
/** php tests/ProductPageTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductDetail;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;

$product = ProductDetail::make([
    'id' => 7,
    'name' => 'Dinosauro Sacchetta <Verde>',
    'url' => 'https://shop.test/prodotto/dinosauro-sacchetta-verde/',
    'short_description' => '<p>Sacchetta con cordino 34x44 cm</p>',
    'description_html' => '<p>Cotone 100%</p>',
    'brand' => 'Elena & Co.',
    'category' => 'Asilo',
    'variant' => 'Verde',
    'currency' => 'EUR',
    'images' => [
        ['url' => 'https://shop.test/media/dinosauro.jpg', 'alt' => 'Dinosauro verde'],
    ],
    'offers' => [[
        'product_id' => 42,
        'item_id' => '42',
        'name' => 'Standard',
        'sku' => 'SKU-42',
        'gtin' => '1234567890123',
        'mpn' => 'DINO-VERDE',
        'regular_price' => 18,
        'sale_price' => 14,
        'stock_managed' => true,
        'available' => true,
    ]],
]);

check('lo schema Product e valido e coincide con prezzo, valuta e disponibilita visibili', function () use ($product) {
    $schema = $product->schemaOrg();
    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    return is_string($json)
        && json_decode($json, true, flags: JSON_THROW_ON_ERROR)['@type'] === 'Product'
        && $schema['offers']['price'] === 14.0
        && $schema['offers']['priceCurrency'] === 'EUR'
        && $schema['offers']['availability'] === 'https://schema.org/InStock'
        && $schema['productID'] === '42'
        && $schema['sku'] === 'SKU-42'
        && $schema['gtin'] === '1234567890123'
        && $schema['description'] === 'Sacchetta con cordino 34x44 cm';
});

check('la disponibilita schema.org viene omessa quando il negozio non vende a giacenza', function () {
    $product = ProductDetail::make([
        'id' => 8,
        'name' => 'Prodotto su ordinazione',
        'url' => 'https://shop.test/prodotto/su-ordinazione/',
        'offers' => [[
            'product_id' => 43,
            'regular_price' => 20,
            'stock_managed' => false,
            'available' => false,
        ]],
    ]);

    return $product->data()['available'] === true
        && $product->data()['stock_managed'] === false
        && !array_key_exists('availability', $product->schemaOrg()['offers']);
});

check('view_product usa identificativo e numeri coerenti con schema e carrello', function () use ($product) {
    $event = $product->viewProductEvent();
    $item = $event['ecommerce']['items'][0];

    return $event['event'] === 'view_product'
        && $event['product']['id'] === '42'
        && $event['product']['price'] === 14.0
        && $event['product']['discount'] === 4.0
        && $item['item_id'] === '42'
        && $item['item_category'] === 'Asilo'
        && $item['item_variant'] === 'Verde'
        && $product->data()['selected_product_id'] === 42;
});

check('il dataLayer e inizializzato, anonimizza gli ospiti e resetta ecommerce', function () use ($product) {
    $script = DataLayer::script([
        'type' => 'product',
        'language' => 'it',
        'currency' => 'EUR',
    ], $product->viewProductEvent());

    return str_contains($script, 'window.dataLayer = window.dataLayer || [];')
        && str_contains($script, 'window.dataLayer.push({ ecommerce: null });')
        && str_contains($script, '"user":{"id":null}')
        && str_contains($script, '"event":"view_product"')
        && str_contains($script, '\\u003CVerde\\u003E')
        && !str_contains($script, '<Verde>');
});

check('la pagina espone route, SEO, breadcrumb visibile e JSON-LD senza duplicare BreadcrumbList', function () {
    $root = dirname(__DIR__);
    $route = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductController.php');
    $view = (string) file_get_contents($root.'/view/pages/frontend/product.php');

    foreach (['title', 'description', 'url', 'image', 'robots', 'breadcrumb'] as $property) {
        if (!str_contains($controller, '$SEO->'.$property)) {
            return false;
        }
    }

    return str_contains($route, "'/prodotto/{slug}/'")
        && str_contains($route, "name('ecommerce.catalog.product')")
        && (str_contains($view, '$SEO->schemaOrg') || str_contains($view, "$"."GLOBALS['SEO']->schemaOrg"))
        && str_contains($view, "Breadcrumb::make(")
        && str_contains($view, 'View::head(DataLayer::script(')
        && str_contains($view, "ProductList::make(")
        && str_contains($view, "data-product-options")
        && substr_count($view, '<h1') === 1
        && !str_contains($view, 'BreadcrumbList');
});

check('l\'opzione chiesta dalla query vince sulla prima disponibile, se esiste', function () {
    $scheda = static fn (int $preferita): int => ProductDetail::make([
        'id' => 1,
        'name' => 'Maglietta',
        'url' => 'https://shop.test/prodotto/maglietta/',
        'stock_managed' => true,
        'offers' => [
            ['product_id' => 42, 'item_id' => '42', 'name' => 'S', 'sku' => 'M-S', 'regular_price' => 10, 'stock_managed' => true, 'available' => true],
            ['product_id' => 43, 'item_id' => '43', 'name' => 'M', 'sku' => 'M-M', 'regular_price' => 10, 'stock_managed' => true, 'available' => false],
        ],
        'preferred_product_id' => $preferita,
    ])->data()['selected_product_id'];

    return $scheda(43) === 43 && $scheda(99) === 42 && $scheda(0) === 42;
});

check('la variante ha la sua route, il controller rimanda con un 301, gli indirizzi hanno un solo costruttore', function () {
    $root = dirname(__DIR__);
    $route = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductController.php');
    $handler = (string) file_get_contents($root.'/http/frontend/product.php');
    $catalog = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductCatalog.php');
    $listing = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductListing.php');

    return str_contains($route, "'/prodotto/{slug}/{variante}/'")
        && str_contains($route, "name('ecommerce.catalog.product.variant')")
        && str_contains($controller, 'ProductCatalog::resolve(')
        && str_contains($controller, ', 301)')
        && str_contains($handler, "\$ROUTE_PARAMETERS['variante']")
        && !str_contains($catalog, 'function productUrl')
        && !str_contains($listing, 'function productUrl')
        && str_contains($catalog, 'OptionQuery::match(')
        && str_contains($catalog, "'slug' => OptionQuery::slug(");
});

check('il cambio d\'opzione riscrive la query senza aggiungere cronologia', function () {
    $view = (string) file_get_contents(dirname(__DIR__).'/view/pages/frontend/product.php');

    return str_contains($view, 'history.replaceState(')
        && !str_contains($view, 'history.pushState(')
        && str_contains($view, 'remember()');
});

check('i bottoni rapidi stanno sotto «Aggiungi» e seguono la disponibilità dell\'offerta', function () {
    $view = (string) file_get_contents(dirname(__DIR__).'/view/pages/frontend/product.php');

    return str_contains($view, 'data-express-product')
        && str_contains($view, "ExpressCheckout::render('product', ")
        && str_contains($view, 'ExpressCheckout::available()')
        && str_contains($view, 'expressBox.hidden = !offer || !offer.available;');
});

check('i gruppi di un multiprodotto escono normalizzati, coi limiti dentro le opzioni', function () {
    $data = ProductDetail::make([
        'name' => 'Cesto',
        'offers' => [['product_id' => 9, 'regular_price' => 39, 'available' => true]],
        'bundle' => [
            'components' => [['name' => 'Pane', 'quantity' => '2']],
            'groups' => [
                ['id' => 3, 'name' => 'Vino', 'min' => 1, 'max' => 1, 'options' => [
                    ['id' => 31, 'name' => 'Rosso', 'surcharge' => '0.00'],
                    ['id' => 32, 'name' => 'Bianco', 'surcharge' => '5.00'],
                ]],
                ['id' => 4, 'name' => 'Dolci', 'min' => 5, 'max' => 9, 'options' => [['id' => 41, 'name' => 'Torrone']]],
                ['id' => 5, 'name' => 'Vuoto', 'min' => 1, 'max' => 1, 'options' => []],
            ],
            'value' => '0',
        ],
    ])->data()['bundle'];

    return $data['components'] === [['name' => 'Pane', 'quantity' => 2.0]]
        && count($data['groups']) === 2
        && $data['groups'][0]['options'][1]['surcharge'] === 5.0
        && $data['groups'][1]['min'] === 1 && $data['groups'][1]['max'] === 1
        && $data['value'] === null;
});

check('la scheda mostra i gruppi di scelta, ne fa rispettare min/max e toglie i bottoni rapidi', function () {
    $view = (string) file_get_contents(dirname(__DIR__).'/view/pages/frontend/product.php');
    $add = (string) file_get_contents(dirname(__DIR__).'/view/components/cart/add.php');

    return str_contains($view, 'data-bundle-groups')
        && str_contains($view, "FormField::key('choices_'.")
        && str_contains($view, 'data-min=')
        && str_contains($view, 'data-max=')
        && str_contains($view, '$expressHere = false;')
        && str_contains($add, '<?=$fields?>');
});

check('in offerta il prezzo pieno compare barrato e segue opzione e sovrapprezzi', function () {
    $view = (string) file_get_contents(dirname(__DIR__).'/view/pages/frontend/product.php');

    return str_contains($view, '<s class="text fw-400 tx-dark ml-2" data-product-regular')
        && str_contains($view, "\$shownOffer['regular_price'] > \$shownOffer['price']")
        && str_contains($view, 'offer.regular_price > offer.price')
        && str_contains($view, 'Number(regulars[productId]) * 100) + cents');
});

summary();
