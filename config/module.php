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
        'listing_entity' => 'model',
        'category_max_depth' => 8,
        'new_days' => 30,
        'collection_tag' => 'collezione',
        // La card prodotto, nel catalogo e negli slider.
        'card' => [
            // Miniature delle varianti sotto la card, con quella della card selezionata.
            'variants' => true,
            // «Aggiungi al carrello» nella card. Se la card ha più prodotti
            // (taglie, scelte, personalizzazioni) porta alla scheda.
            'add_to_cart' => false,
            // Quantità con −/+ accanto al bottone della card.
            'quantity' => false,
        ],
    ],
    // La scheda prodotto.
    'product' => [
        // Quantità con −/+ accanto ad «Aggiungi al carrello».
        'quantity' => false,
        // Bottoni del pagamento rapido (Apple Pay, Google Pay, Link) sotto «Aggiungi al carrello».
        'express' => true,
        // `auto`: bottoni per opzioni visuali o fino a 4 valori, select oltre.
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
    // Partial del sito innestate negli slot delle view sigillate (piano 2):
    // chiave dello slot => percorso della partial dentro `custom/`.
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
        'panel' => \Wonder\Auth\Frontend\AccountPanel::class,
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
        'actor_authorities' => ['admin', 'administrator'],
        'token_ttl' => 120,
    ],
];
