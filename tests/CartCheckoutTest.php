<?php
/** php tests/CartCheckoutTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$root = dirname(__DIR__);

/** Le pagine e i parziali del checkout, uniti: i ganci possono stare in un parziale. */
function checkoutViews(string $root): string
{
    $files = array_merge(glob($root.'/view/pages/checkout/*.php') ?: [], glob($root.'/view/components/checkout/*.php') ?: []);

    return implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
}

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
        && str_contains($routes, "Route::get('/preview/'")
        && str_contains($routes, "['cart_action' => 'quantity']")
        && str_contains($routes, "['cart_action' => 'remove']")
        && str_contains($routes, "Route::name('ecommerce.checkout.')")
        && str_contains($routes, "['checkout_action' => 'place']")
        && str_contains($routes, "['checkout_action' => 'completed']");
});

check('il mini-carrello usa le utility del design system e conserva il fallback HTML', function () use ($root) {
    $shell = (string) file_get_contents($root.'/view/components/cart/mini-cart.php');
    $body = (string) file_get_contents($root.'/view/components/cart/mini-cart-body.php');
    $add = (string) file_get_contents($root.'/view/components/cart/add.php');
    $script = (string) file_get_contents($root.'/resources/assets/js/mini-cart.js');

    return str_contains($shell, 'id="cart-offpage"')
        && str_contains($shell, 'class="p-f top end full-page no-interaction intro"')
        && str_contains($shell, "__r('ecommerce.cart.preview')")
        && str_contains($shell, "__r('ecommerce.cart.index')")
        && str_contains($add, 'data-ecommerce-add-to-cart')
        && str_contains($script, '[data-ecommerce-cart-trigger]')
        && str_contains($script, 'class Cart')
        && str_contains($script, 'HTMLFormElement.prototype.submit.call(form)')
        && !str_contains($shell.$body.$add, "render('wonder')")
        && !is_file($root.'/resources/assets/css/mini-cart.css');
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
    $view = checkoutViews($root);

    return $config['checkout']['guest_enabled'] === false
        && str_contains($controller, "Ecommerce::config('checkout.guest_enabled', false)")
        && str_contains($controller, "RecaptchaGuard::for('ecommerce_checkout')")
        && str_contains($view, "->recaptcha('ecommerce_checkout')");
});

check('il checkout non finge il completamento dei provider online non collegati', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($controller, 'CheckoutForm::isManual($method)')
        && str_contains($controller, 'ecommerce.checkout.errors.provider_pending')
        && str_contains($controller, 'Checkout::place')
        && str_contains($controller, 'ecommerce_checkout_completed');
});

check('summary e coupon hanno le guardie in JSON e place usa CheckoutForm', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($routes, "['checkout_action' => 'summary']")
        && str_contains($routes, "['checkout_action' => 'coupon']")
        && str_contains($c, "'error' => (string) __t('ecommerce.checkout.summary_error')], 419)")
        && str_contains($c, "'redirect' => self::loginUrl()], 401)")
        && str_contains($c, "'redirect' => self::route('ecommerce.cart.index')], 409)")
        && str_contains($c, 'CheckoutForm::data(')
        && !str_contains($c, "'shipping_method_id' => 0");
});

check('login, registrazione e Google conservano il ritorno al checkout', function () use ($root) {
    $core = dirname((new ReflectionClass(\Wonder\Auth\Frontend\AuthProfile::class))->getFileName(), 4);
    $form = (string) file_get_contents($core.'/app/view/components/frontend/account/auth-form.php');
    $federated = (string) file_get_contents($core.'/app/view/components/frontend/account/federated.php');
    $controller = (string) file_get_contents($core.'/class/Auth/Frontend/AuthController.php');

    return str_contains($form, "FormField::key('continue')->hidden()")
        && str_contains($federated, 'values[name] = source.value')
        && str_contains($federated, '#login [name], #sign_up [name]')
        && str_contains($controller, '$PAGE->redirectBase64 = base64_encode($continue)');
});

check('carrello e checkout hanno layout sigillati e form identificabili da GTM', function () use ($root) {
    $cart = (string) file_get_contents($root.'/view/pages/cart/index.php');
    $checkout = checkoutViews($root);
    $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);

    return in_array('pages/cart', $manifest['views']['sealed'] ?? [], true)
        && in_array('pages/checkout', $manifest['views']['sealed'] ?? [], true)
        && is_file($root.'/view/layout/frontend/ecommerce.shop.php')
        && is_file($root.'/view/layout/frontend/ecommerce.checkout.php')
        && str_contains($cart, 'id="cart_quantity_')
        && str_contains($cart, 'id="cart_remove_')
        && str_contains($checkout, 'id="checkout"');
});

check('la pagina del checkout ha i ganci per consegna, sedi, coupon e il solo form di invio', function () use ($root) {
    $view = checkoutViews($root);
    $hooks = ['data-checkout-fulfillment', 'data-checkout-shipping-methods', 'data-checkout-pickup-locations',
        'data-checkout-payments', 'data-checkout-lines', 'data-checkout-totals', 'data-checkout-notices',
        'data-summary-url', 'data-coupon-url', 'data-initial', 'data-checkout-submit', "'id' => 'checkout-coupon'",
        'data-checkout-line', 'data-checkout-toggle', 'data-checkout-total'];

    foreach ($hooks as $hook) {
        if (!str_contains($view, $hook)) {
            return false;
        }
    }

    foreach (glob($root.'/view/pages/checkout/*.php') as $page) {
        if (substr_count((string) file_get_contents($page), '<h1') !== 1) {
            return false;
        }
    }

    return str_contains($view, "->attr('form', 'checkout')")
        && str_contains($view, "module_asset('ecommerce', 'js/checkout.js')")
        && str_contains($view, "'event' => 'begin_checkout'");
});

check('i testi usati dalla pagina del checkout esistono in italiano e in inglese', function () use ($root) {
    $view = checkoutViews($root)."\n".(string) file_get_contents($root.'/view/pages/cart/index.php');
    preg_match_all("/__t\('ecommerce\.([a-z_.]+)'\)/", $view, $found);

    foreach (['it', 'en'] as $lang) {
        $lines = json_decode((string) file_get_contents($root.'/lang/'.$lang.'/ecommerce.json'), true);

        foreach (array_unique($found[1]) as $key) {
            $cursor = $lines;

            foreach (explode('.', $key) as $part) {
                $cursor = is_array($cursor) ? ($cursor[$part] ?? null) : null;
            }

            if (!is_string($cursor) || $cursor === '') {
                return false;
            }
        }
    }

    return true;
});

check('checkout.js scarta le risposte vecchie, aspetta una pausa e racconta a GTM le scelte', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return str_contains($js, 'class Checkout')
        && str_contains($js, 'this.sequence')
        && str_contains($js, 'if (sequence !== this.sequence)')
        && str_contains($js, 'setTimeout')
        && str_contains($js, '300')
        && str_contains($js, 'textContent')
        && !str_contains($js, 'innerHTML')
        && str_contains($js, "'add_shipping_info'")
        && str_contains($js, "'add_payment_info'")
        && str_contains($js, 'dataLayer.push({ ecommerce: null })')
        && str_contains($js, 'payload.redirect')
        && str_contains($js, 'X-Requested-With')
        && str_contains($js, 'window.ecommerceCheckout');
});

check('una modifica del cliente scarta le risposte in viaggio e la prima visita tiene le scelte del carrello', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = checkoutViews($root);

    return preg_match('/schedule\(\) \{\s*\/\/[^\n]*\n\s*this\.sequence\+\+;/', $js) === 1
        && str_contains($js, 'this.submit(this.latest)')
        && str_contains($c, "\$flash['values'] === []")
        && str_contains($c, 'CartSession::user(), !$json)')
        && str_contains($view, 'CartPresenter::lines(');
});

check('il passo Carrello usa il layout del checkout, i passi e il coupon che torna al carrello', function () use ($root) {
    $cart = (string) file_get_contents($root.'/view/pages/cart/index.php');
    $parts = checkoutViews($root);
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);

    return str_contains($cart, "Ecommerce::layout('checkout'")
        && str_contains($cart, 'data-checkout-cart')
        && str_contains($cart, "'step' => 'cart'")
        && str_contains($parts, 'Steps::make(')
        && str_contains($parts, 'wi-thumb')
        && str_contains($parts, '<template data-checkout-line>')
        && str_contains($parts, "FormField::key('return')->hidden()")
        && str_contains($parts, '<details')
        && str_contains($controller, "'coupon_applied'")
        && str_contains($controller, 'CartController::flash(')
        && in_array('components/checkout', $manifest['views']['sealed'] ?? [], true)
        && !str_contains($cart.$parts, "render('wonder')");
});

check('il Pagamento chiede la Spedizione completa e place ordina dal carrello', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = (string) @file_get_contents($root.'/view/pages/checkout/payment.php');

    return str_contains($routes, "['checkout_action' => 'payment']")
        && !is_file($root.'/view/pages/checkout/index.php')
        && str_contains($c, 'CheckoutRules::deliveryErrors(')
        && str_contains($c, 'self::fromCart(')
        && str_contains($c, 'CheckoutRules::paymentErrors(')
        && str_contains($c, 'registerBaseConsents(')
        && str_contains($c, "'ecommerce.checkout.errors.shipping_incomplete'")
        && str_contains($view, 'data-step="payment"')
        && str_contains($view, "__r('ecommerce.checkout.place')")
        && str_contains($view, "Choice::make('same_as_shipping'")
        && str_contains($view, "Choice::make('invoice'")
        && str_contains($view, '->acceptDocument(')
        && str_contains($view, "#contatto")
        && str_contains($view, "#consegna");
});

check('il passo Spedizione salva contatto e consegna e porta al Pagamento', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = (string) @file_get_contents($root.'/view/pages/checkout/shipping.php');

    return str_contains($routes, "['checkout_action' => 'shipping']")
        && str_contains($c, 'CheckoutRules::deliveryErrors(')
        && str_contains($c, "\$post['shipping_phone']")
        && str_contains($c, "self::route('ecommerce.checkout.payment')")
        && str_contains($view, 'id="checkout"')
        && str_contains($view, 'data-step="shipping"')
        && str_contains($view, 'id="contatto"')
        && str_contains($view, 'id="consegna"')
        && str_contains($view, "__r('ecommerce.checkout.shipping')")
        && str_contains($view, 'ChoiceGroup::make(')
        && str_contains($view, 'data-checkout-choice')
        && str_contains($view, 'data-checkout-inline-submit')
        && substr_count($view, '<h1') === 1;
});

check('checkout.js lavora a passi, clona i template e non manda due volte', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return str_contains($js, "querySelector('[data-checkout]')")
        && str_contains($js, 'dataset.step')
        && str_contains($js, '[data-checkout-line]')
        && str_contains($js, '[data-checkout-choice]')
        && str_contains($js, 'querySelectorAll(\'[data-checkout-lines]\')')
        && str_contains($js, 'data-checkout-toggle')
        && str_contains($js, "'pageshow'")
        && str_contains($js, 'data-checkout-locked')
        && str_contains($js, '.content.cloneNode(true)');
});

check('le viste dei passi reggono i float del sito: ogni div ha una larghezza e nessuna griglia è anche uno span', function () use ($root) {
    // Nel sito ogni div dentro una section galleggia: senza w-* si stringe al contenuto.
    $views = checkoutViews($root)."\n".file_get_contents($root.'/view/pages/cart/index.php');
    preg_match_all('/<div\b(.*?)(?<!\?)>/s', $views, $divs);
    $narrow = array_filter($divs[1], static fn (string $attrs): bool => preg_match('/class="[^"]*\bw-\d+/', $attrs) !== 1);

    return $divs[1] !== [] && $narrow === []
        && preg_match('/class="[^"]*\bcol-\d+ col-t-\d+\b[^"]*\bd-grid\b/', $views) !== 1;
});

check('ogni icona di pagamento ha il suo SVG e la licenza', function (): bool {
    $dir = dirname(__DIR__).'/resources/assets/payment-icons';
    foreach (array_keys(\Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::ICONS) as $key) {
        $svg = (string) @file_get_contents($dir.'/'.$key.'.svg');
        if (!str_contains($svg, '<svg') || str_contains($svg, '<script')) {
            return false;
        }
    }

    return str_contains((string) @file_get_contents($dir.'/LICENSE'), 'MIT');
});

check('il check-out rapido non ha ancora bottoni (arrivano coi pagamenti online)', fn () =>
    \Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCheckout::buttons(['total' => '10.00']) === []
);

check('accesso, account e checkout stampano il loro font', function (): bool {
    $dir = dirname(__DIR__).'/view/layout/frontend/';
    foreach (['auth', 'account', 'checkout'] as $area) {
        if (!str_contains((string) file_get_contents($dir.'ecommerce.'.$area.'.php'), "StoreFont::style('".$area."')")) {
            return false;
        }
    }

    return true;
});

check('i testi della pagina unica ci sono in italiano e in inglese, quelli dei passi no', function (): bool {
    foreach (['it', 'en'] as $lingua) {
        $t = json_decode((string) file_get_contents(dirname(__DIR__).'/lang/'.$lingua.'/ecommerce.json'), true);
        $c = (array) ($t['checkout'] ?? []);
        foreach (['express', 'or', 'secure', 'logout', 'billing_different', 'field_required', 'shipping_pending', 'shipping_methods_pending', 'redirect_panel'] as $chiave) {
            if (trim((string) ($c[$chiave] ?? '')) === '') {
                return false;
            }
        }
        if (isset($c['steps']) || isset($c['continue_payment']) || isset($c['edit']) || isset($c['errors']['shipping_incomplete'])
            || !str_contains((string) $c['field_required'], '{{label}}') || !str_contains((string) $c['redirect_panel'], '{{name}}')) {
            return false;
        }
    }

    $it = json_decode((string) file_get_contents(dirname(__DIR__).'/lang/it/ecommerce.json'), true);

    return $it['cart']['products_total'] === 'Subtotale' && $it['checkout']['submit_online'] === 'Paga ora'
        && $it['checkout']['submit_manual'] === 'Ordina';
});

summary();
