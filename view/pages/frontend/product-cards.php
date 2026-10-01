<?php

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;
use Wonder\View\View;

global $SEO;

if (is_object($SEO ?? null)) {
    $SEO->title = 'Componenti card prodotto';
    $SEO->description = 'Anteprima dei layout griglia, swiper e lista del modulo ecommerce.';
    $SEO->robots = false;
}

$demoImage = static function (string $label, string $from, string $to): string {
    $label = htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = <<<SVG
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800">
        <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient></defs>
        <rect width="800" height="800" fill="url(#g)"/>
        <circle cx="400" cy="320" r="210" fill="rgba(255,255,255,.16)"/>
        <text x="400" y="665" text-anchor="middle" fill="white" font-family="system-ui,sans-serif" font-size="54" font-weight="700">{$label}</text>
    </svg>
    SVG;

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$products = [
    [
        'id' => 101,
        'name' => 'T-shirt Essential',
        'url' => '#product-101',
        'image' => $demoImage('Essential', '#5b6cff', '#a65bff'),
        'image_alt' => 'T-shirt Essential',
        'price' => 39.90,
        'sale_price' => 29.90,
        'short_description' => 'Jersey di cotone biologico, vestibilità regolare.',
        'variants' => [
            ['name' => 'Blu', 'url' => '#blu', 'active' => true],
            ['name' => 'Viola', 'url' => '#viola'],
            ['name' => 'Nero', 'url' => '#nero'],
        ],
    ],
    [
        'id' => 102,
        'name' => 'Felpa Studio',
        'url' => '#product-102',
        'image' => $demoImage('Studio', '#ff7d54', '#ffbf69'),
        'image_alt' => 'Felpa Studio',
        'price' => 74.00,
        'short_description' => 'Felpa pesante con interno spazzolato e dettagli tono su tono.',
        'badge' => 'Novità',
    ],
    [
        'id' => 103,
        'name' => 'Borsa Everyday',
        'url' => '#product-103',
        'image' => $demoImage('Everyday', '#159f83', '#72d49c'),
        'image_alt' => 'Borsa Everyday',
        'price' => 58.50,
        'short_description' => 'Tela robusta, tasca interna e tracolla regolabile.',
    ],
    [
        'id' => 104,
        'name' => 'Cappellino Logo',
        'url' => '#product-104',
        'image' => $demoImage('Logo', '#20242c', '#687180'),
        'image_alt' => 'Cappellino Logo',
        'price' => 26.00,
        'short_description' => 'Sei pannelli, chiusura regolabile e ricamo frontale.',
    ],
];

$gridCode = <<<'PHP'
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;

echo ProductList::make($products)
    ->title('Prodotti in evidenza', 'Una selezione dal catalogo')
    ->columns(4, 3, 2)
    ->gap(5)
    ->renderGrid();
PHP;

$swiperCode = <<<'PHP'
echo ProductList::make($products)
    ->title('Scopri anche')
    ->swiper(
        slidesPerView: 1.2,
        spaceBetween: 12,
        breakpoints: [
            640 => ['slidesPerView' => 2, 'spaceBetween' => 16],
            1024 => ['slidesPerView' => 4, 'spaceBetween' => 24],
        ],
        navigation: true,
        pagination: false,
    )
    ->renderSwiper();
PHP;

$listCode = <<<'PHP'
echo ProductList::make($products)
    ->title('Tutti i prodotti')
    ->emptyMessage('Nessun prodotto disponibile')
    ->renderList();
PHP;

$codeBlock = static fn (string $code): string => '<pre class="wi-box p-4 mt-4 o-auto"><code>'
    .htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    .'</code></pre>';

View::layout('frontend.main');
?>
<main>
    <section class="intro">
        <div class="content">
            <div class="w-90 w-t-100">
                <p class="text-small tx-secondary mb-2">Ecommerce / Componenti</p>
                <h1 class="title">Card prodotto</h1>
                <p class="text mt-3 mb-10">Le tre composizioni disponibili usano lo stesso array di prodotti e cambiano soltanto il renderer finale.</p>

                <section class="mb-12">
                    <?=ProductList::make($products)
                        ->title('Griglia', 'Layout responsive a colonne.')
                        ->columns(4, 3, 2)
                        ->renderGrid()?>
                    <?=$codeBlock($gridCode)?>
                </section>

                <section class="mb-12">
                    <?=ProductList::make($products)
                        ->title('Swiper', 'Carosello responsive basato sul componente Swiper del core.')
                        ->renderSwiper()?>
                    <?=$codeBlock($swiperCode)?>
                </section>

                <section class="mb-12">
                    <?=ProductList::make($products)
                        ->title('Lista', 'Card orizzontali con descrizione breve.')
                        ->renderList()?>
                    <?=$codeBlock($listCode)?>
                </section>
            </div>
        </div>
    </section>
</main>
<?php View::end(); ?>
