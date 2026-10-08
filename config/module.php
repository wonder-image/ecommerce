<?php

return [
    // Classi del sito che estendono Extensions\EcommerceExtension.
    'extensions' => [],
    'catalog' => [
        'currency' => 'EUR',
        'index_url' => '/prodotti/',
        'category_url' => '/prodotti/',
        'per_page' => 12,
        // `model`: una card per articolo; `variant`: una card per variante.
        'listing_entity' => 'model',
        // Mostra sotto la card le miniature delle altre varianti.
        'card_show_variants' => true,
        'category_max_depth' => 8,
        'new_days' => 30,
        'collection_tag' => 'collezione',
        // `auto`: bottoni per opzioni visuali o fino a 4 valori, select oltre.
        // Un sito puo forzare il controllo per slug: ['taglia' => 'buttons'].
        'option_selector' => [
            'default' => 'auto',
            'attributes' => [],
        ],
        'recommendations_limit' => 12,
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
        'actor_authorities' => ['admin', 'administrator'],
        'token_ttl' => 120,
    ],
];
