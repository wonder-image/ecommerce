<?php

return [
    // Classi del sito che estendono Extensions\EcommerceExtension.
    'extensions' => [],
    // Partial del sito innestate negli slot delle view sigillate (piano 2):
    // chiave dello slot => percorso della partial dentro `custom/`.
    'slots' => [],
    'auth' => [
        'completion_token_ttl' => 86400,
        'password_reset_ttl' => 1800,
        'federated' => [
            'google' => true,
            'apple' => false,
        ],
    ],
    'checkout' => [
        // Predisposto per la fase checkout: nessun acquisto ospite finche il
        // commerciante non lo abilita esplicitamente dal backend.
        'guest_enabled' => false,
    ],
    'account' => [
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
