<?php
/** php tests/ProductComponentsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../vendor/wonder-image/app/app/function/helper.php';
require __DIR__.'/harness.php';

defined('APP_URL') || define('APP_URL', '');
// Le immagini delle card passano da Product::image(): il core versiona gli URL.
defined('ASSETS_VERSION') || define('ASSETS_VERSION', 'test');
defined('APP_VERSION') || define('APP_VERSION', 'test');
defined('ROOT') || define('ROOT', sys_get_temp_dir());
defined('RESPONSIVE_IMAGE_SIZES') || define('RESPONSIVE_IMAGE_SIZES', [240, 480, 960, 1440]);

// Le frecce dello swiper hanno etichette tradotte: si leggono dal lang/ del modulo.
\Wonder\Localization\LanguageContext::addLangPath(__DIR__.'/../lang');
\Wonder\Localization\TranslationProvider::init();

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCard;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;

$products = [
    [
        'id' => 12,
        'name' => 'T-shirt <Blu>',
        'url' => '/shop/t-shirt-blu/',
        'image' => '/media/t-shirt.jpg',
        'price' => 39.9,
        'sale_price' => 29.9,
        'short_description' => 'Cotone & lino',
        'variants' => [
            ['name' => 'Blu', 'url' => '/shop/t-shirt-blu/', 'active' => true],
            ['name' => 'Nero'],
        ],
    ],
    [
        'id' => 18,
        'name' => 'Felpa',
        'image' => '/media/felpa.jpg',
        'price_formatted' => '59,00 €',
    ],
];

check('la card normalizza i dati nel formato corrente', function () use ($products) {
    $sale = ProductCard::make($products[0])->data();
    $regular = ProductCard::make($products[1])->data();

    return $sale['price'] === '29,90 €'
        && $sale['compare_at_price'] === '39,90 €'
        && $sale['badge'] === 'In offerta'
        && $regular['name'] === 'Felpa'
        && $regular['image'] === '/media/felpa.jpg'
        && $regular['price'] === '59,00 €';
});

check('la griglia rende card sicure e classi responsive', function () use ($products) {
    $html = ProductList::make($products)
        ->title('Prodotti')
        ->columns(5, 3, 1)
        ->gap(4)
        ->renderGrid();

    return str_contains($html, 'product-list--grid')
        && str_contains($html, 'product-list--grid w-100 d-grid col-5 col-t-3 col-p-1 gap-4')
        && str_contains($html, '<div class="product-list__header mb-6 w-100')
        && !str_contains($html, '<header')
        && str_contains($html, 'product-card__media p-r d-block f-1-1')
        && str_contains($html, 'bg bg-contain')
        && !str_contains($html, 'bg-cover')
        && !str_contains($html, 'class="p-a top start w-100 h-100 bg bg-cover"')
        && !str_contains($html, 'product-card product-card--grid wi-box')
        && str_contains($html, 'T-shirt &lt;Blu&gt;')
        && str_contains($html, '29,90 €')
        && !str_contains($html, 'T-shirt <Blu>');
});

check('la lista usa la card orizzontale e conserva gli id mostrati', function () use ($products) {
    $list = ProductList::make($products);
    $html = $list->renderList();

    return str_contains($html, 'product-list--list')
        && str_contains($html, 'product-list--list d-grid gap-4 w-100')
        && str_contains($html, 'product-card--list')
        && str_contains($html, 'product-card__media p-r d-block f-1-1')
        && !str_contains($html, 'product-card product-card--list wi-box')
        && str_contains($html, 'Cotone &amp; lino')
        && $list->shownIds() === [12, 18];
});

check('le card usano solo componenti e utility del design system', function () use ($products) {
    $html = ProductList::make($products)->renderGrid().ProductList::make($products)->renderList();

    return str_contains($html, 'badge badge-primary')
        && str_contains($html, 'product-card__variant d-block p-r b-1 o-hidden')
        && str_contains($html, 'product-card__name d-block text fw-500 tx-black tx-none')
        && !str_contains($html, 'style=')
        && !str_contains($html, 'px-')
        && !str_contains($html, 'py-');
});

check('lo stato vuoto mantiene titolo e messaggio personalizzato', function () {
    $html = ProductList::make()
        ->title('Novità')
        ->emptyMessage('Torna presto')
        ->renderGrid();

    return str_contains($html, 'Novità')
        && str_contains($html, 'Torna presto')
        && str_contains($html, 'role="status"');
});

check('le viste e i valori di layout non validi falliscono subito', function () {
    try {
        ProductList::make()->view('tabella');
        return false;
    } catch (InvalidArgumentException) {
    }

    try {
        ProductList::make()->columns(0);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
});

check('la vista swiper rende card e configurazione responsive del core', function () use ($products) {
    $html = ProductList::make($products)
        ->swiper(1.25, 10, [720 => ['slidesPerView' => 3, 'spaceBetween' => 20]])
        ->renderSwiper();

    return str_contains($html, 'product-list--swiper')
        && str_contains($html, 'swiper w-100')
        && str_contains($html, 'swiper-slide')
        && str_contains($html, 'slidesPerView: 1.25')
        && str_contains($html, '720')
        && str_contains($html, 'data-product-list-nav=')
        && str_contains($html, 'data-product-list-prev')
        && str_contains($html, 'data-product-list-next');
});

check('la pagina dimostrativa non e esposta dalle route pubbliche', function () {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $docs = (string) file_get_contents($root.'/docs/product-cards.md');

    return !is_file($root.'/view/pages/frontend/product-cards.php')
        && !str_contains($routes, '/ecommerce/product-cards/')
        && str_contains($docs, 'demo/product-cards.php');
});

check('le varianti compaiono da due in su, con quella della card selezionata', function () use ($products) {
    $two = ProductList::make([$products[0]])->renderGrid();
    $one = ProductList::make([['name' => 'Cappello', 'variants' => [['name' => 'Unico', 'active' => true]]]])->renderGrid();

    return substr_count($two, 'product-card__variant ') === 2
        && substr_count($two, 'aria-current="true"') === 1
        && str_contains($two, '<s class="fw-400 tx-dark">39,90 €</s>')
        && !str_contains($one, 'product-card__variants');
});

check('nella scheda una sola variante mostra i bottoni se l\'articolo ha varianti', function () {
    $render = static function (array $vars): string {
        ob_start();
        (static function () use ($vars): void {
            extract($vars);
            require __DIR__.'/../view/components/catalog/variants.php';
        })();

        return (string) ob_get_clean();
    };
    $one = [['name' => 'Unico', 'url' => '/cappello/', 'active' => true]];

    return str_contains($render(['variants' => $one, 'hasVariants' => true]), 'product-variant btn')
        && !str_contains($render(['variants' => $one, 'hasVariants' => false]), 'product-variant')
        && !str_contains($render(['variants' => $one]), 'product-variant')
        && !str_contains($render(['variants' => [], 'hasVariants' => true]), 'product-variant');
});

check('lo slider rispetta il minimo e il massimo di card', function () use ($products) {
    $many = array_fill(0, 5, $products[1]);

    return ProductList::make($many)->swiperLimits(6, 8)->renderSwiper() === ''
        && substr_count(ProductList::make($many)->swiperLimits(1, 3)->renderSwiper(), 'product-card--grid') === 3;
});

check('la card compra un solo prodotto, con −/+ se richiesto, e altrimenti porta alla scheda', function () use ($products) {
    $card = static fn (array $cart): string => ProductList::make([['name' => 'Ombrello', 'url' => '/shop/ombrello/', 'cart' => $cart]])->renderGrid();
    $stepper = $card(['product_id' => 7, 'available' => true, 'quantity' => true]);
    $plain = $card(['product_id' => 7, 'available' => true]);
    $soldOut = $card(['product_id' => 7, 'available' => false]);
    $choose = $card(['product_id' => 0]);
    $none = ProductList::make([$products[0]])->renderGrid();

    return str_contains($stepper, 'data-ecommerce-add-to-cart')
        && str_contains($stepper, 'name="product_id"')
        && str_contains($stepper, 'value="7"')
        && str_contains($stepper, 'data-quantity-step="-1"')
        && !str_contains($stepper, 'id="add_to_cart_')
        && str_contains($plain, 'data-ecommerce-add-to-cart')
        && !str_contains($plain, 'data-quantity-step')
        && str_contains($soldOut, 'disabled')
        && !str_contains($soldOut, 'data-ecommerce-add-to-cart')
        && str_contains($choose, 'href="/shop/ombrello/"')
        && !str_contains($choose, '<form')
        && !str_contains($none, 'product-card__cart');
});

check('il catalogo non contiene compatibilita con i campi legacy', function () {
    $root = dirname(__DIR__);
    $sources = implode("\n", [
        (string) file_get_contents($root.'/src/Frontend/Catalog/ProductCard.php'),
        (string) file_get_contents($root.'/docs/product-cards.md'),
    ]);

    foreach (['fullName', 'imageS', 'prettyPrice', 'prettySale'] as $legacyField) {
        if (str_contains($sources, $legacyField)) {
            return false;
        }
    }

    return true;
});

check('le FAQ: domanda protetta, risposta HTML, titolo tradotto o tolto, niente senza domande', function () {
    $faq = static fn (array $data): string => \Wonder\View\View::component(
        \Wonder\Plugin\Ecommerce\Ecommerce::viewPath('components/catalog/faq.php'),
        $data
    );
    $html = $faq(['faqs' => [
        ['id' => 1, 'question' => 'Spedite <ovunque>?', 'answer' => '<p>Sì, in <strong>tutta Europa</strong>.</p>'],
        ['id' => 2, 'question' => '   ', 'answer' => '<p>Vuota</p>'],
    ]]);

    return str_contains($html, 'Spedite &lt;ovunque&gt;?')
        && str_contains($html, '<strong>tutta Europa</strong>')
        && str_contains($html, 'Domande frequenti')
        && substr_count($html, 'wi-dropdown-box') === 1
        // Nelle section la lib fa float ai div: senza w-100 la griglia si stringe.
        && str_contains($html, 'd-grid col-1 gap-3 w-100')
        && !str_contains($faq(['faqs' => [['id' => 1, 'question' => 'Q', 'answer' => 'A']], 'title' => null]), '<h2')
        && trim($faq(['faqs' => []])) === '';
});

check('la selezione in primo piano: foto 1:1 dal lato scelto, card sopra lo sfondo, immagini da __ri()', function () use ($products) {
    $spotlight = static fn (array $meta, string $background): string => \Wonder\View\View::component(
        \Wonder\Plugin\Ecommerce\Ecommerce::viewPath('components/catalog/selection/spotlight.php'),
        ['selection' => ['title' => 'Alba', 'subtitle' => '', 'text' => '<p>Testo</p>', 'image' => '', 'background' => $background, 'meta' => $meta, 'products' => [$products[0]]], 'card' => '<article class="product-card">x</article>']
    );
    $left = $spotlight(['image_side' => 'left'], '');
    $right = $spotlight(['image_side' => 'right'], '/media/sfondo.jpg');

    return str_contains($left, 'f-1-1')
        && str_contains($left, 'data-wi-image-name="t-shirt.jpg"')
        && !str_contains($left, 'order: 2')
        && !str_contains($left, 'sfondo')
        && str_contains($left, '<h2 class="title tx-primary">Alba</h2>')
        && str_contains($left, '<p>Testo</p>')
        && str_contains($right, 'order: 2')
        && str_contains($right, 'data-wi-image-name="sfondo.jpg"');
});

check('il blocco della selezione manda view_item_list e prepara select_item', function () {
    $event = ['event' => 'view_item_list', 'ecommerce' => ['item_list_id' => 'home-articoli', 'item_list_name' => 'A </script>', 'items' => []]];
    $html = \Wonder\View\View::component(
        \Wonder\Plugin\Ecommerce\Ecommerce::viewPath('components/catalog/selection/block.php'),
        ['html' => '<p>card</p>', 'event' => $event]
    );

    return str_contains($html, '<p>card</p>')
        && str_contains($html, '"item_list_id":"home-articoli"')
        && !str_contains($html, 'A </script>')
        && str_contains($html, "'select_item'");
});

check('il testo della selezione va nell\'intestazione dell\'elenco', function () use ($products) {
    $html = ProductList::make($products)->title('Titolo', null, '<p>Due righe</p>')->render('grid');

    return str_contains($html, '<div class="text mt-2"><p>Due righe</p></div>');
});

check('il bottone della selezione va nell\'intestazione, anche senza titolo; senza link niente bottone', function () use ($products) {
    $html = ProductList::make($products)->action('Vedi <tutti>', '/prodotti/')->render('grid');

    return str_contains($html, 'href="/prodotti/"')
        && str_contains($html, 'Vedi &lt;tutti&gt;')
        && !str_contains(ProductList::make($products)->action('Vedi', '')->render('grid'), 'Vedi');
});

summary();
