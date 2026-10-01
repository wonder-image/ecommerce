<?php
/** php tests/EcommerceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Ecommerce\Ecommerce;

$root = dirname(__DIR__);

check('i percorsi del modulo puntano dentro il pacchetto', fn () =>
    Ecommerce::root() === $root
    && Ecommerce::manifestPath() === $root.'/module.json'
    && Ecommerce::langPath() === $root.'/lang'
    && Ecommerce::handlerPath('frontend/cart.php') === $root.'/http/frontend/cart.php'
    && Ecommerce::assetPath('js/cart.js') === $root.'/resources/assets/js/cart.js'
);

// Senza $ROOT (comandi forge, test) l'override del sito non esiste: si prende
// sempre la view del pacchetto.
check('viewPath cade sul modulo quando il sito non ha override', function () use ($root) {
    unset($GLOBALS['ROOT']);

    return Ecommerce::viewPath('pages/frontend/stato.php') === $root.'/view/pages/frontend/stato.php';
});

check('la configurazione tiene disabilitato di default il checkout ospite', function () use ($root) {
    $config = require $root.'/config/module.php';

    return $config['extensions'] === []
        && $config['slots'] === []
        && $config['checkout']['guest_enabled'] === false
        && $config['auth']['federated']['google'] === true
        && $config['auth']['federated']['apple'] === false;
});

check('le view auth sono sigillate', fn () => in_array('pages/auth', Ecommerce::sealedViews(), true));

check('il pannello account e le sue azioni appartengono al modulo', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');

    return in_array('pages/account', Ecommerce::sealedViews(), true)
        && is_file($root.'/view/layout/frontend/ecommerce.account.php')
        && is_file($root.'/view/components/account/row.php')
        && str_contains($routes, "Route::name('ecommerce.account.')")
        && str_contains($routes, "->guarded()")
        && str_contains($routes, "->permit(['client'])")
        && str_contains($routes, "'payment-methods' => '/payment-methods/'")
        && str_contains($routes, "'shipping.edit'");
});

check('il portale Stripe resta disabilitato finche manca il customer id', function () use ($root) {
    $config = require $root.'/config/module.php';

    return ($config['account']['payment_methods']['provider'] ?? null) === 'stripe'
        && ($config['account']['payment_methods']['enabled'] ?? null) === false;
});

check('l’alert auth appartiene al layout di pagina e non ai form', function () use ($root) {
    $layout = (string) file_get_contents($root.'/view/layout/frontend/ecommerce.auth.php');
    $alertPosition = strpos($layout, '$authAlert');
    $sectionPosition = strpos($layout, '<section>');

    foreach (glob($root.'/view/pages/auth/*.php') ?: [] as $page) {
        if (str_contains((string) file_get_contents($page), 'components/auth/errors.php')) {
            return false;
        }
    }

    return $alertPosition !== false
        && $sectionPosition !== false
        && $alertPosition < $sectionPosition;
});

check('la registrazione richiede consensi ecommerce ma non dati fiscali o regolamento di gioco', function () use ($root) {
    $files = [
        $root.'/src/Frontend/Auth/AuthValidator.php',
        $root.'/view/pages/auth/signup-request.php',
        $root.'/view/pages/auth/signup-completion.php',
    ];
    $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));

    return str_contains($source, 'privacy_policy')
        && str_contains($source, 'terms_conditions')
        && !str_contains($source, 'formBilling')
        && !str_contains($source, 'game_rules');
});

check('tutti i form auth pubblici richiedono una action reCAPTCHA dedicata', function () use ($root) {
    $actions = [
        'login' => 'ecommerce_login',
        'signup-request' => 'ecommerce_signup_request',
        'signup-completion' => 'ecommerce_signup_completion',
        'password-recovery' => 'ecommerce_password_recovery',
        'password-restore' => 'ecommerce_password_restore',
    ];

    foreach ($actions as $page => $action) {
        $source = (string) file_get_contents($root.'/view/pages/auth/'.$page.'.php');
        if (!str_contains($source, "->recaptcha('{$action}')")
            || !str_contains($source, 'wi-submit')) {
            return false;
        }
    }

    $controller = (string) file_get_contents($root.'/src/Frontend/Auth/AuthController.php');

    return str_contains($controller, 'RecaptchaGuard::for($action)')
        && str_contains($controller, "withService('ecommerce-auth')");
});

check('Google federato conserva csrf senza dipendere da consensi o reCAPTCHA', function () use ($root) {
    $component = (string) file_get_contents($root.'/view/components/auth/federated.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Auth/AuthController.php');

    return str_contains($component, 'accounts.google.com/gsi/client')
        && str_contains($controller, 'self::requireCsrf();')
        && !str_contains($component, 'accept_privacy_policy')
        && !str_contains($component, 'accept_terms_conditions')
        && !str_contains($controller, 'federated_consents_required')
        && !str_contains($controller, 'registerUserConsents($result->userId')
        && !str_contains($component, 'g-recaptcha-token')
        && !str_contains($controller, '$recaptchaAction')
        && !str_contains($component, 'AppleID');
});

check('il login manuale indirizza gli account senza password al provider federato', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Auth/AuthController.php');
    $alerts = (string) file_get_contents($root.'/src/Frontend/Auth/AuthValidationAlert.php');
    $translations = (string) file_get_contents($root.'/lang/it/ecommerce.json');

    return str_contains($controller, 'hasLocalPassword')
        && str_contains($controller, 'federatedProviderForUser')
        && str_contains($controller, "'use_federated_login_'.\$provider")
        && str_contains($alerts, 'use_federated_login_google')
        && str_contains($translations, 'use_google');
});

check('il campo cellulare usa prefisso breve e numero esteso anche nel profilo', function () use ($root) {
    $profile = (string) file_get_contents($root.'/view/pages/account/profile.php');

    return str_contains($profile, 'd-grid col-4 col-p-1 gap-4')
        && str_contains($profile, '<div class="col-3 col-p-1">')
        && str_contains($profile, "FormField::key('phone_prefix')->phonePrefix()")
        && str_contains($profile, "FormField::key('phone')->phone()");
});

check('auth e account valorizzano i metadati SEO', function () use ($root) {
    $auth = (string) file_get_contents($root.'/src/Frontend/Auth/AuthController.php');
    $account = (string) file_get_contents($root.'/src/Frontend/Account/AccountController.php');

    foreach (['title', 'description', 'url', 'breadcrumb', 'robots'] as $property) {
        if (!str_contains($auth, '$SEO->'.$property)
            || !str_contains($account, '$SEO->'.$property)) {
            return false;
        }
    }

    return true;
});

check('i form espongono id semantici per Google Tag Manager', function () use ($root) {
    $forms = [
        'view/pages/auth/login.php' => 'login',
        'view/pages/auth/signup-request.php' => 'sign_up',
        'view/pages/auth/signup-completion.php' => 'sign_up_completion',
        'view/pages/auth/password-recovery.php' => 'password_recovery',
        'view/pages/auth/password-restore.php' => 'password_restore',
        'view/components/account/navigation.php' => 'logout',
        'view/pages/account/profile.php' => 'update_profile',
        'view/pages/backend/impersonation.php' => 'impersonate_user',
    ];

    foreach ($forms as $file => $id) {
        if (!str_contains((string) file_get_contents($root.'/'.$file), 'id="'.$id.'"')) {
            return false;
        }
    }

    $address = (string) file_get_contents($root.'/view/pages/account/address-form.php');
    $federated = (string) file_get_contents($root.'/view/components/auth/federated.php');

    return str_contains($address, "'update_billing_address'")
        && str_contains($address, "'save_shipping_address'")
        && str_contains($federated, "'google_sign_up'")
        && str_contains($federated, "'google_login'");
});

summary();
