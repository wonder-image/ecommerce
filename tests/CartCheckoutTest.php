<?php
/** php tests/CartCheckoutTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$root = dirname(__DIR__);

check('il presenter formatta totali e quantità senza dipendere dalla view', fn () =>
    CartPresenter::money(1234.5) === '1.234,50 €'
    && CartPresenter::quantity(2.500) === '2,5'
    && CartPresenter::count([
        ['type' => 'product', 'quantity' => 2],
        ['type' => 'product', 'quantity' => 1.5],
        ['type' => 'fee', 'quantity' => 1],
    ]) === '3,5'
);

check('le route pubbliche espongono carrello e checkout con mutazioni POST', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');

    return str_contains($routes, "Route::name('ecommerce.cart.')")
        && str_contains($routes, "Route::post('/add/'")
        && str_contains($routes, "['cart_action' => 'quantity']")
        && str_contains($routes, "['cart_action' => 'remove']")
        && str_contains($routes, "Route::name('ecommerce.checkout.')")
        && str_contains($routes, "['checkout_action' => 'place']")
        && str_contains($routes, "['checkout_action' => 'completed']");
});

check('il cookie ospite è isolato dai carrelli collegati a un cliente', function () use ($root) {
    $source = (string) file_get_contents($root.'/src/Frontend/Cart/CartSession.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Cart/CartController.php');

    return str_contains($source, "'customer_id' => 0")
        && str_contains($source, 'Cart::merge')
        && str_contains($source, "'httponly' => true")
        && str_contains($source, "'samesite' => 'Lax'")
        && str_contains($source, 'if (!$create)')
        && str_contains($controller, 'CartSession::current(false)');
});

check('tutte le mutazioni del carrello e del checkout verificano il CSRF', function () use ($root) {
    $cart = (string) file_get_contents($root.'/src/Frontend/Cart/CartController.php');
    $checkout = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $add = (string) file_get_contents($root.'/view/components/cart/add.php');

    return str_contains($cart, 'self::requireCsrf();')
        && str_contains($checkout, 'self::requireCsrf();')
        && str_contains($add, "FormField::key('csrf_token')->hidden()")
        && !str_contains($add, "render('wonder')");
});

check('il checkout ospite resta disattivato per default e usa reCAPTCHA se abilitato', function () use ($root) {
    $config = require $root.'/config/module.php';
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = (string) file_get_contents($root.'/view/pages/checkout/index.php');

    return $config['checkout']['guest_enabled'] === false
        && str_contains($controller, "Ecommerce::config('checkout.guest_enabled', false)")
        && str_contains($controller, "RecaptchaGuard::for('ecommerce_checkout')")
        && str_contains($view, "->recaptcha('ecommerce_checkout')");
});

check('il checkout non finge il completamento dei provider online non collegati', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($controller, "!== 'manual'")
        && str_contains($controller, 'ecommerce.checkout.errors.provider_pending')
        && str_contains($controller, 'Checkout::place')
        && str_contains($controller, 'ecommerce_checkout_completed');
});

check('login, registrazione e Google conservano il ritorno al checkout', function () use ($root) {
    $login = (string) file_get_contents($root.'/view/pages/auth/login.php');
    $signup = (string) file_get_contents($root.'/view/pages/auth/signup-request.php');
    $federated = (string) file_get_contents($root.'/view/components/auth/federated.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Auth/AuthController.php');

    return str_contains($login, "FormField::key('continue')->hidden()")
        && str_contains($signup, "FormField::key('continue')->hidden()")
        && str_contains($federated, "['continue'].forEach")
        && str_contains($controller, '$PAGE->redirectBase64 = base64_encode($continue)');
});

check('carrello e checkout hanno layout sigillati e form identificabili da GTM', function () use ($root) {
    $cart = (string) file_get_contents($root.'/view/pages/cart/index.php');
    $checkout = (string) file_get_contents($root.'/view/pages/checkout/index.php');
    $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);

    return in_array('pages/cart', $manifest['views']['sealed'] ?? [], true)
        && in_array('pages/checkout', $manifest['views']['sealed'] ?? [], true)
        && is_file($root.'/view/layout/frontend/ecommerce.shop.php')
        && is_file($root.'/view/layout/frontend/ecommerce.checkout.php')
        && str_contains($cart, 'id="cart_quantity_')
        && str_contains($cart, 'id="cart_remove_')
        && str_contains($checkout, 'id="checkout"');
});

summary();
