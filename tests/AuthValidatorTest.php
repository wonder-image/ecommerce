<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthValidator;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthValidationAlert;
use Wonder\Plugin\Ecommerce\Support\SafeRedirect;

check('il primo passaggio richiede solo identita e consensi ecommerce', function () {
    $valid = AuthValidator::signupRequest([
        'name' => 'Ada',
        'surname' => 'Lovelace',
        'email' => 'ada@example.test',
        'accept_privacy_policy' => 'true',
        'accept_terms_conditions' => 'true',
    ]);

    return $valid === [];
});

check('il secondo passaggio richiede cellulare e password coerenti', function () {
    $errors = AuthValidator::completion([
        'phone_prefix' => '+39',
        'phone' => '3331234567',
        'password' => 'password-forte',
        'password_confirmation' => 'password-forte',
    ]);

    return $errors === []
        && AuthValidator::canonicalPhone(['phone_prefix' => '+39', 'phone' => '333 123 4567']) === '+393331234567';
});

check('il secondo passaggio federato mantiene obbligatorio il cellulare ma non la password locale', fn () =>
    AuthValidator::completion([
        'phone_prefix' => '+39',
        'phone' => '3331234567',
        'password' => '',
        'password_confirmation' => '',
    ], false) === []
    && isset(AuthValidator::completion([
        'phone_prefix' => '+39',
        'phone' => '',
    ], false)['phone'])
);

check('redirect esterni non fidati vengono rifiutati', fn () =>
    SafeRedirect::fromRequest('https://evil.example/checkout', '/account/') === '/account/'
    && SafeRedirect::fromRequest('/checkout/', '/') === '/checkout/'
);

check('gli errori del secondo passaggio diventano dettagli nello stesso alert', fn () =>
    AuthValidationAlert::messageKeys([
        'phone' => 'required',
        'password' => 'too_short',
        'password_confirmation' => 'mismatch',
    ]) === [
        'ecommerce.auth.validation.errors.phone_required',
        'ecommerce.auth.validation.errors.password_too_short',
        'ecommerce.auth.validation.errors.password_mismatch',
    ]
);

summary();
