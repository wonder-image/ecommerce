<?php
/** php tests/GoogleApisTest.php — le API Google che il negozio dichiara al registro del core. */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Module\Contracts\ModuleGoogleApis;
use Wonder\App\Support\GoogleCloud\GoogleApi;
use Wonder\Plugin\Ecommerce\Ecommerce;

/** @return array<string, GoogleApi> */
function apiGoogleEcommerce(?bool $google = null): array
{
    Ecommerce::forgetConfig();
    if ($google !== null) {
        $config = Ecommerce::config();
        $config['auth']['federated']['google'] = $google;
        (new ReflectionProperty(Ecommerce::class, 'config'))->setValue(null, $config);
    }

    $apis = [];
    foreach (Ecommerce::googleApis() as $api) {
        $apis[$api->key()] = $api;
    }
    Ecommerce::forgetConfig();

    return $apis;
}

check('l\'entrypoint implementa ModuleGoogleApis', fn () => is_subclass_of(Ecommerce::class, ModuleGoogleApis::class));

check('reCAPTCHA, Maps JavaScript, Places e Accedi con Google', function () {
    $apis = apiGoogleEcommerce();

    return array_keys($apis) === ['recaptcha_enterprise', 'maps_javascript', 'places', 'google_oauth']
        && array_filter($apis, static fn ($api) => !$api instanceof GoogleApi) === [];
});

check('tutte obbligatorie e usate da ecommerce con il login Google acceso', function () {
    foreach (apiGoogleEcommerce(true) as $api) {
        if (!$api->isRequired() || $api->getUsedBy() !== [Ecommerce::SLUG]) {
            return false;
        }
    }

    return true;
});

check('Accedi con Google facoltativo se il sito spegne auth.federated.google', function () {
    $apis = apiGoogleEcommerce(false);

    return !$apis['google_oauth']->isRequired()
        && $apis['recaptcha_enterprise']->isRequired()
        && $apis['maps_javascript']->isRequired()
        && $apis['places']->isRequired();
});

check('unita a quella del core rende obbligatoria Places e somma gli utilizzatori', function () {
    $core = GoogleApi::make('places')->fields(['gcp_client_api_key'])->usedBy('app');
    $merged = $core->merge(apiGoogleEcommerce()['places']);

    return $merged->isRequired() && $merged->getUsedBy() === ['app', 'ecommerce'];
});

summary();
