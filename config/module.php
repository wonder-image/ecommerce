<?php

return [
    // Classi del sito che estendono Extensions\EcommerceExtension.
    'extensions' => [],
    'catalog' => [
        'currency' => 'EUR',
        'index_url' => '/prodotti/',
        'category_url' => '/prodotti/',
        // Massimo di prodotti per pagina del catalogo.
        'per_page' => 12,
        // Card per riga nella griglia del catalogo, da 1 a 12.
        'columns' => [
            'desktop' => 4,
            'tablet' => 3,
            'phone' => 2,
        ],
        // Slider di prodotti (simili, visti di recente, potrebbero piacerti):
        // compaiono da `min` prodotti in su e ne mostrano al massimo `max`.
        // `columns` sono le card per riga, anche decimali per far vedere la successiva.
        'swiper' => [
            'min' => 1,
            'max' => 12,
            'columns' => [
                'desktop' => 4,
                'tablet' => 2,
                'phone' => 1.2,
            ],
        ],
        // `model`: una card per articolo; `variant`: una card per variante.
        // Decimali dei prezzi mostrati, da 0 a 4: `product` nella scheda,
        // `card` nelle card, `totals` in carrello, checkout e ordini.
        // I dati strutturati (schema.org, dataLayer) restano a precisione piena.
        'price_decimals' => [
            'product' => 2,
            'card' => 2,
            'totals' => 2,
        ],
        'listing_entity' => 'model',
        'category_max_depth' => 8,
        'new_days' => 30,
        'collection_tag' => 'collezione',
        // La card prodotto, nel catalogo e negli slider.
        'card' => [
            // Miniature delle varianti sotto la card, con quella della card selezionata.
            'variants' => true,
            // `contain` o `cover` per le miniature tonde delle varianti.
            'variants_fit' => 'contain',
            // «Aggiungi al carrello» nella card. Se la card ha più prodotti
            // (taglie, scelte, personalizzazioni) porta alla scheda.
            'add_to_cart' => false,
            // Quantità con −/+ accanto al bottone della card.
            'quantity' => false,
            // Formato della foto, fra quelli della lib: 1:1, 4:5, 3:4, 2:3, 4:3, 16:9…
            'image_format' => '1:1',
            // `contain`: la foto intera nel box; `cover`: riempie il box e si taglia.
            // Formato e fit valgono anche per le righe di carrello, checkout e ordini.
            'image_fit' => 'contain',
            // Descrizione breve sotto il nome nella card della griglia.
            'short_description' => false,
        ],
    ],
    // La scheda prodotto.
    'product' => [
        // Quantità con −/+ accanto ad «Aggiungi al carrello».
        'quantity' => false,
        // Bottoni del pagamento rapido (Apple Pay, Google Pay, Link) sotto «Aggiungi al carrello».
        'express' => true,
        // Formato delle foto della galleria e delle sue miniature, fra quelli della lib.
        'image_format' => '4:5',
        'thumbs_format' => '1:1',
        // `contain` o `cover`, per la galleria e le sue miniature.
        'image_fit' => 'contain',
        // `auto`: bottoni per opzioni visuali o fino a 4 valori, select oltre.
        // `group`: btn-group a scelta singola, il default degli attributi di
        // tipo «Bottone» del gestionale.
        // Un sito puo forzare il controllo per slug: ['taglia' => 'buttons'].
        'option_selector' => [
            'default' => 'auto',
            'attributes' => [],
        ],
    ],
    'cart' => [
        // Bottoni del pagamento rapido nel riepilogo, sopra «Procedi». Nel checkout ci sono sempre.
        'express' => true,
    ],
    'checkout' => [
        // Ordini dell'ospite (che non ha il limite per cliente): [tentativi, secondi]
        // per indirizzo IP e per carrello. 0 al posto della coppia spegne quel limite.
        // L'IP è quello del core: dietro un proxy serve APP_TRUST_PROXY.
        'guest_order_limit' => [
            'ip' => [10, 900],
            'cart' => [5, 600],
        ],
    ],
    // Partial del sito negli slot delle view sigillate (elenco in docs/slots.md):
    // nome dello slot => componente (`slots.cart-banner` è
    // `custom/view/components/frontend/slots/cart-banner.php`), percorso assoluto o lista.
    'slots' => [],
    'auth' => [
        // Il sito puo estendere il profilo del core: campi, validazione e hook.
        'profile' => \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile::class,
        'completion_token_ttl' => 86400,
        'password_reset_ttl' => 1800,
        'federated' => [
            'google' => true,
            'apple' => false,
        ],
    ],
    'account' => [
        // Un pannello del sito estende EcommerceAccountPanel per tenere gli slot `account.*`.
        'panel' => \Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountPanel::class,
        'navigation' => [],
        'payment_methods' => [
            'provider' => 'stripe',
            // Si abilita solo quando il gestionale espone il customer Stripe
            // da usare per creare una sessione Billing Portal lato server.
            'enabled' => false,
        ],
    ],
    'impersonation' => [
        'enabled' => true,
        // Chi può entrare nell'account di un cliente: area `impersonazione` in
        // `permissions`. `actor_authorities` qui è deprecato: se un sito lo
        // imposta vince sull'area.
        'token_ttl' => 120,
    ],
    // Aree permessi dell'ecommerce (Wonder\App\Permission\Access). `admin`
    // (Wonder Image) è sempre incluso. Per cambiarle nel sito: `permissions` in
    // custom/config/modules/ecommerce.php; ogni area indicata sostituisce
    // per intero quella di default.
    'permissions' => [
        // Conta l'azione `edit`: con una mappa per azione indicare `edit` o `*`.
        'impersonazione' => ['administrator'],
    ],
];
