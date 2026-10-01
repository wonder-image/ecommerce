# Card prodotto

`ProductCard` normalizza una singola voce di catalogo; `ProductList` la dispone
come griglia, elenco orizzontale o carosello Swiper. Le classi non eseguono
query: ricevono array, oggetti o DTO, quindi la sorgente dati può cambiare senza
dover riscrivere le viste.

Il markup usa esclusivamente componenti e utility di `wonder-image/lib`: non è
richiesto CSS dedicato. La card è volutamente minimale, con immagine quadrata,
badge del design system, prezzo in evidenza e varianti circolari facoltative.

La pagina dimostrativa completa è disponibile su
`/ecommerce/product-cards/`.

```php
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;

$products = [
    [
        'id' => 12,
        'name' => 'T-shirt girocollo',
        'url' => '/shop/t-shirt-girocollo/',
        'image' => '/assets/upload/prodotti/t-shirt.jpg',
        'price' => 39.90,
        'sale_price' => 29.90,
        'short_description' => 'Cotone biologico.',
        'variants' => [
            ['name' => 'Blu', 'url' => '/shop/t-shirt-girocollo/?color=blu', 'active' => true],
            ['name' => 'Nero', 'url' => '/shop/t-shirt-girocollo/?color=nero'],
        ],
    ],
];

echo ProductList::make($products)
    ->title('In evidenza', 'Una selezione dal catalogo')
    ->columns(4, 3, 2)
    ->renderGrid();
```

Gli altri layout usano gli stessi dati:

```php
echo ProductList::make($products)->renderList();

echo ProductList::make($products)
    ->swiper(1.2, 12, [
        640 => ['slidesPerView' => 2, 'spaceBetween' => 16],
        1024 => ['slidesPerView' => 4, 'spaceBetween' => 24],
    ])
    ->renderSwiper();
```

Gli ID effettivamente mostrati sono disponibili con `shownIds()`.

Ogni vista può essere pubblicata/ridefinita dal sito sotto
`custom/modules/ecommerce/view/components/catalog/product/`, senza modificare
il pacchetto.
