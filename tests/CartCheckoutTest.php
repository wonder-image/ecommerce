<?php
/** php tests/CartCheckoutTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutFields;

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

check('il checkout ospite si sceglie dal backend, non dalla configurazione, e usa reCAPTCHA se abilitato', function () use ($root) {
    $config = require $root.'/config/module.php';
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = checkoutViews($root);

    return !isset($config['checkout']['guest_enabled'])
        && str_contains($controller, 'GuestCheckout::enabled()')
        && !str_contains($controller, "Ecommerce::config('checkout.guest_enabled'")
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

    return str_contains((string) @file_get_contents($root.'/view/pages/checkout/index.php'), "->attr('data-checkout-submit', '')")
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
        && str_contains($c, '$formState === []')
        && str_contains($c, 'CartSession::user(), !$json)')
        && str_contains($view, 'CartPresenter::lines(');
});

check('il carrello sta nel layout del negozio, col suo font, senza passi e senza SKU', function () use ($root): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/cart/index.php');
    $parts = checkoutViews($root);
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);

    return str_contains($v, "Ecommerce::layout('shop'")
        && str_contains($v, "StoreFont::style('cart')")
        && str_contains($v, 'data-checkout-cart')
        && !str_contains($v, 'Steps::make(') && !str_contains($v, 'steps.php')
        && !str_contains($v, "labels['sku']") && !str_contains($v, 'data-step')
        && str_contains($parts, 'wi-thumb')
        && str_contains($parts, '<template data-checkout-line>')
        && str_contains($parts, "FormField::key('return')->hidden()")
        && str_contains($parts, '<details')
        && str_contains($controller, "'coupon_applied'")
        && str_contains($controller, 'FlashMessage::success(')
        && in_array('components/checkout', $manifest['views']['sealed'] ?? [], true)
        && !str_contains($v.$parts, "render('wonder')");
});

check('carrello e checkout affidano i messaggi dopo redirect al FlashMessage del core', function () use ($root): bool {
    $cart = (string) file_get_contents($root.'/src/Frontend/Cart/CartController.php');
    $checkout = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $layouts = (string) file_get_contents($root.'/view/layout/frontend/ecommerce.shop.php')
        .(string) file_get_contents($root.'/view/layout/frontend/ecommerce.checkout.php');

    return str_contains($cart, 'FlashMessage::success(')
        && str_contains($cart, 'FlashMessage::error(')
        && str_contains($checkout, 'FlashMessage::success(')
        && str_contains($checkout, 'FlashMessage::error(')
        && !str_contains($layouts, 'Alert::make(')
        && !str_contains($layouts, '$notice')
        && !str_contains($layouts, '$errors');
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

check('accesso, account, checkout e carrello caricano le misure dei testi del negozio', function (): bool {
    $root = dirname(__DIR__);
    $files = [
        $root.'/view/layout/frontend/ecommerce.auth.php', $root.'/view/layout/frontend/ecommerce.account.php',
        $root.'/view/layout/frontend/ecommerce.checkout.php', $root.'/view/pages/cart/index.php',
    ];
    foreach ($files as $file) {
        if (!str_contains((string) file_get_contents($file), 'StoreStyle::sheet()')) {
            return false;
        }
    }
    $layout = (string) file_get_contents($root.'/view/layout/frontend/ecommerce.checkout.php');

    return str_contains((string) file_get_contents($root.'/src/Frontend/StoreStyle.php'), "module_asset('ecommerce', 'css/store.css')")
        && str_contains($layout, '<main>') && str_contains($layout, '</main>');
});

check('store.css: sottotitoli 20, testi, bottoni e valori 14, label 14 che sale a 12, input più bassi', function (): bool {
    $css = (string) file_get_contents(dirname(__DIR__).'/resources/assets/css/store.css');
    $vars = [
        '--font-size: 14px;', '--subtitle-font-size: 20px;', '--text-font-size: 14px;', '--text-small-font-size: 14px;',
        '--button-font-size: 14px;', '--input-font-size: 14px;', '--input-line-height: 20px;',
        '--input-label-font-size: 14px;', '--input-label-focus-font-size: 12px;',
    ];
    foreach ($vars as $var) {
        if (!str_contains($css, $var)) {
            return false;
        }
    }

    return str_contains($css, 'main {') && str_contains($css, 'font-size: var(--font-size);') && str_contains($css, 'main .wi-input-container .wi-input {')
        && str_contains($css, 'main .wi-input-container.compiled .wi-label {')
        && str_contains($css, 'min-height: calc(var(--input-line-height) + 28px')
        && str_contains($css, 'top: calc(48px + var(--input-border-top));');
});

check('store.css: input e tendine con 12px ai lati, opzioni come i campi, testi piccolissimi a 12', function (): bool {
    $css = (string) file_get_contents(dirname(__DIR__).'/resources/assets/css/store.css');

    return str_contains($css, '--text-xsmall-font-size: 12px;') && str_contains($css, 'main .text-xsmall {')
        && str_contains($css, 'main .wi-choice__panel {') && str_contains($css, 'padding-left: 12px;')
        && str_contains($css, 'calc(100% - 24px - var(--input-border-left) - var(--input-border-right))')
        && str_contains($css, 'main .wi-input-container .wi-input-list .wi-input-list-value')
        && str_contains($css, 'padding: 8px 12px;');
});

check('store.css: i pulsanti accanto ai campi sono alti come gli input', function (): bool {
    $css = (string) file_get_contents(dirname(__DIR__).'/resources/assets/css/store.css');

    return str_contains($css, 'main .btn.wi-input-submit {') && str_contains($css, 'line-height: var(--input-line-height) !important;')
        && str_contains($css, 'padding: 14px 16px !important;');
});

check('checkout: avviso di spedizione piccolissimo, griglie dei campi con gap 3 sul telefono', function (): bool {
    $view = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');

    return str_contains($view, 'text-xsmall" data-checkout-notice') && !str_contains($view, 'gap-p-2')
        && substr_count($view, 'd-grid col-4 gap-4 gap-p-3') === 5 && substr_count($view, 'mt-4 mt-p-3') >= 3;
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

check('il checkout è una pagina sola: niente rotte dei passi', function (): bool {
    $rotte = (string) file_get_contents(dirname(__DIR__).'/config/routes/route.frontend.php');
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');

    return !str_contains($rotte, "'checkout_action' => 'shipping'") && !str_contains($rotte, "'checkout_action' => 'payment'")
        && str_contains($rotte, "['checkout_action' => 'place']")
        && !str_contains($c, 'function shipping(') && !str_contains($c, 'function payment(')
        && !str_contains($c, 'shippingDone') && !str_contains($c, 'backToShipping')
        && str_contains($c, "pages/checkout/index.php");
});

check('place passa dalle regole: POST pulito, errori insieme, solo i metodi offerti', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');
    $place = substr($c, (int) strpos($c, 'private static function place('));

    return str_contains($place, 'CheckoutRules::post($_POST, CartSession::user())')
        && str_contains($place, 'CheckoutRules::deliveryErrors(')
        && str_contains($place, 'CheckoutRules::paymentErrors(')
        && str_contains($place, 'CheckoutRules::method(')
        && str_contains($place, 'CheckoutForm::isManual(')
        && !str_contains($place, "ecommerce.checkout.payment'");
});

check('il riepilogo usa lo stesso POST della pagina', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($c, 'CheckoutSummary::payload($cartId, CheckoutRules::post($_POST, CartSession::user()), CartSession::user())');
});

check('la pagina unica ha le sezioni nell\'ordine giusto e il bottone nel form', function (): bool {
    $v = (string) @file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $pos = static fn (string $s): int => ($p = strpos($v, $s)) === false ? -1 : $p;
    $ordine = [$pos('id="contatti"'), $pos('id="consegna"'), $pos('id="pagamento"'), $pos('id="fatturazione"'), $pos('data-checkout-submit')];
    $form = substr($v, $pos('<form id="checkout"'), $pos('</form>') - $pos('<form id="checkout"'));

    return !in_array(-1, $ordine, true) && $ordine === array_values(array_unique($ordine)) && $ordine == array_values((function ($o) { sort($o); return $o; })($ordine))
        && str_contains($form, 'data-checkout-submit')
        && str_contains($v, "variant('segmented')") && str_contains($v, "variant('list')")
        && str_contains($v, "->icon('truck')") && str_contains($v, "->icon('shop')")
        && str_contains($v, '->icons(') && str_contains($v, '->panel(')
        && str_contains($v, 'same_as_shipping:0') && str_contains($v, 'billing_type:business')
        && str_contains($v, "ecommerce.auth.logout") && str_contains($v, "StoreFont") === false
        && !file_exists(dirname(__DIR__).'/view/pages/checkout/shipping.php')
        && !file_exists(dirname(__DIR__).'/view/pages/checkout/payment.php')
        && !file_exists(dirname(__DIR__).'/view/components/checkout/steps.php');
});

check('il riepilogo è sticky e le righe non hanno lo SKU', function (): bool {
    $css = (string) @file_get_contents(dirname(__DIR__).'/resources/assets/css/checkout.css');
    $righe = (string) file_get_contents(dirname(__DIR__).'/view/components/checkout/lines.php');

    return str_contains($css, 'position: sticky') && str_contains($css, '560px')
        && !str_contains($righe, 'data-line-sku') && str_contains($righe, '64px');
});

check('il riepilogo è un box grigio che resta fermo: la section del layout non taglia lo sticky', function (): bool {
    $css = (string) file_get_contents(dirname(__DIR__).'/resources/assets/css/checkout.css');
    $layout = (string) file_get_contents(dirname(__DIR__).'/view/layout/frontend/ecommerce.checkout.php');

    return !str_contains($css, '100vmax') && str_contains($css, 'border-radius')
        && str_contains($layout, '<section class="wi-checkout-section">')
        && (bool) preg_match('/\.wi-checkout-section\s*\{[^}]*overflow:\s*visible/', $css);
});

check('i pannelli nascosti del checkout spariscono anche se sono griglie', fn (): bool =>
    (bool) preg_match('/\.wi-checkout \[hidden\]\s*\{\s*display:\s*none\s*!important/', (string) file_get_contents(dirname(__DIR__).'/resources/assets/css/checkout.css'))
);

check('senza sedi per il ritiro la scelta spedizione/ritiro non si vede', function (): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return str_contains($v, "data-checkout-fulfillment<?=\$canPickup ? '' : ' hidden'?>")
        && str_contains($js, 'box.hidden = !pickup');
});

check('i campi indirizzo del checkout hanno le label della lingua', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($c, 'Order::shippingAddress()->labels()') && str_contains($c, 'Order::billingAddress()->labels()')
        && str_contains($c, '->label((string) $labels[$key])');
});

check('il cellulare è prefisso più numero e si chiede una volta sola', function (): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return str_contains($v, "FormField::key('shipping_phone_prefix')->phonePrefix()")
        && str_contains($v, "FormField::key('shipping_phone')->phone()")
        && !str_contains($v, "FormField::key('phone')")
        && str_contains($c, "'billing_phone_prefix', 'billing_phone'")
        && !str_contains($js, "['phone', 'billing_phone']");
});

check('con la fattura il codice fiscale c\'è per privato e azienda, il resto solo per l\'azienda', function (): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');

    return str_contains($v, '$invoice_fields') && !str_contains($v, '$cf_field') && !str_contains($v, '$business_fields')
        && !str_contains($v, 'data-checkout-toggle="billing_type:private"') && str_contains($v, "\$key === 'billing_cf'");
});

check('le tasse seguono la fatturazione: anteprima e prima visita passano dalle regole', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return str_contains($c, 'CheckoutSummary::payload($cartId, CheckoutRules::post($values, CartSession::user())')
        && str_contains($js, '[name^="billing_"]') && str_contains($js, '[name="same_as_shipping"]') && str_contains($js, '[name="invoice"]');
});

check('aperti o chiusi i pannelli, checkout.js fa ricontrollare il pulsante Ordina alla lib', function (): bool {
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');
    $toggles = substr($js, (int) strpos($js, '    toggles() {'), 1500);

    return str_contains($toggles, "if (typeof check === 'function') { check(); }");
});

check('checkout.js lavora su una pagina sola', function (): bool {
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return !str_contains($js, 'dataset.step') && !str_contains($js, 'this.step')
        && !str_contains($js, 'data-line-sku') && !str_contains($js, 'innerHTML')
        && str_contains($js, '[data-choice-icons]') && str_contains($js, '[data-choice-panel]')
        && str_contains($js, 'wi-choice__more')
        && str_contains($js, 'field_required') && str_contains($js, "aria-invalid")
        && str_contains($js, 'scrollIntoView')
        && str_contains($js, 'shipping_methods_pending') && str_contains($js, 'address_complete')
        && str_contains($js, 'mirror(')
        && str_contains($js, "'pageshow'") && str_contains($js, 'data-checkout-locked')
        && str_contains($js, 'this.submit(this.latest)')
        && (bool) preg_match('/schedule\(\) \{\s*\/\/[^\n]*\n\s*this\.sequence\+\+;/', $js);
});

check('spedizione e ritiro aprono e chiudono tutte le parti della spedizione', function (): bool {
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return str_contains($js, "querySelectorAll('[data-checkout-shipping]')")
        && !str_contains($js, "querySelector('[data-checkout-shipping]')");
});

check('senza JS il checkout apre tutte le parti che il JS nasconde', function (): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $css = preg_match('~<noscript><style>(.*?)</style></noscript>~s', $v, $m) ? $m[1] : '';

    return str_contains($css, '[data-checkout-toggle][hidden]')
        && str_contains($css, '[data-checkout-shipping][hidden]')
        && str_contains($css, '[data-checkout-pickup][hidden]')
        && str_contains($css, 'display:block!important')
        && str_contains($css, '.d-grid[data-checkout-toggle][hidden]{display:grid!important}');
});

check('indirizzi e fattura stanno su 4 colonne con la larghezza di ogni campo', function (): bool {
    $spans = ['shipping_name' => 2, 'billing_surname' => 2, 'shipping_country' => 2, 'billing_province' => 2,
        'shipping_cap' => 1, 'billing_number' => 1, 'billing_sdi' => 1, 'shipping_phone_prefix' => 1,
        'billing_city' => 3, 'shipping_street' => 3, 'shipping_phone' => 3, 'billing_business_name' => 3,
        'shipping_more' => 4, 'billing_cf' => 4, 'billing_pi' => 4, 'billing_pec' => 4, 'billing_altro' => 4];
    foreach ($spans as $key => $span) {
        if (CheckoutFields::span($key) !== $span) {
            return false;
        }
    }
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');

    return !str_contains($v, 'col-2 col-p-1')
        && substr_count($v, 'd-grid col-4 gap-4') === 5
        && substr_count($v, 'col-<?=CheckoutFields::span($key)?>') >= 3
        && str_contains($v, "col-<?=CheckoutFields::span('shipping_name')?>");
});

check('la fattura mette in fila ragione sociale e SDI, poi partita IVA, codice fiscale e PEC', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');
    $schema = ['billing_cf' => 'cf', 'billing_name' => 'n', 'billing_pec' => 'pec', 'billing_sdi' => 'sdi', 'billing_business_name' => 'b', 'billing_pi' => 'pi'];

    return array_keys(CheckoutFields::pick($schema, CheckoutFields::INVOICE)) === ['billing_business_name', 'billing_sdi', 'billing_pi', 'billing_cf', 'billing_pec']
        && str_contains($c, 'CheckoutFields::pick($billing, CheckoutFields::INVOICE)');
});

check('i campi che il server vuole sono required, e così la label ha l\'asterisco', function (): bool {
    foreach (['shipping_country', 'shipping_city', 'shipping_cap', 'shipping_street', 'billing_name', 'billing_surname', 'billing_country', 'billing_city', 'billing_cap', 'billing_street', 'billing_business_name', 'billing_pi'] as $key) {
        if (!CheckoutFields::required($key)) {
            return false;
        }
    }
    foreach (['shipping_province', 'shipping_number', 'shipping_more', 'billing_sdi', 'billing_pec', 'billing_cf'] as $key) {
        if (CheckoutFields::required($key)) {
            return false;
        }
    }
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($c, 'CheckoutFields::required($key)');
});

check('il codice fiscale è obbligatorio solo per il privato, e il JS lo cambia col tipo', function (): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return str_contains($v, 'data-checkout-required="billing_type:private"')
        && str_contains($v, '$field->required(!$business)')
        && str_contains($js, "querySelectorAll('[data-checkout-required]')")
        && str_contains($js, 'label[for="${CSS.escape(field.id)}-control"]');
});

check('l\'ospite ottiene account e link prima dell\'ordine, e la conferma non mostra l\'account', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $completed = (string) file_get_contents($root.'/view/pages/checkout/completed.php');
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);
    $account = strpos($controller, 'GuestCheckout::account(');
    $place = strpos($controller, 'Checkout::place(');

    return $account !== false && $place !== false && $account < $place
        && str_contains($controller, 'GuestCheckout::passwordLink(')
        && str_contains($controller, "'customer_email'")
        // Il testo non dice se l'account ha una password; si mostra se l'email è partita.
        && str_contains($controller, "'email_sent' => \$guest && (\$result['customer_email_sent'] ?? false)")
        && !str_contains($controller, "'password_link'")
        && str_contains($completed, "ecommerce.checkout.completed.email_sent")
        && str_contains($completed, "empty(\$result['guest'])")
        && is_string($it['checkout']['completed']['email_sent'] ?? null)
        && is_string($en['checkout']['completed']['email_sent'] ?? null)
        && !isset($it['checkout']['completed']['password_sent'])
        && is_string($it['checkout']['completed']['shop'] ?? null)
        && is_string($en['checkout']['completed']['shop'] ?? null);
});

check('il totale non ha la valuta davanti: il simbolo lo mette già money()', function () use ($root): bool {
    $totali = (string) file_get_contents($root.'/view/components/checkout/totals.php');

    return !str_contains($totali, 'wi-checkout__currency')
        && !str_contains((string) file_get_contents($root.'/resources/assets/css/checkout.css'), 'wi-checkout__currency');
});

check('aprendo «Mi serve fattura» è già scelta «Azienda»; una scelta fatta con la fattura resta', function () use ($root): bool {
    $v = (string) file_get_contents($root.'/view/pages/checkout/index.php');

    // Senza fattura il dato salvato è sempre «privato»: non deve decidere la scelta.
    return str_contains($v, "\$business = !\$invoice || (\$values['billing_type'] ?? 'business') === 'business';");
});

check('il carrello ha lo stepper −/+, «Rimuovi» col cestino, la foto a 88px e il codice sconto in un riquadro a parte', function () use ($root): bool {
    $v = (string) file_get_contents($root.'/view/pages/cart/index.php');

    // −/+ mandano la quantità nuova col bottone stesso: niente JS e niente «Aggiorna».
    return substr_count($v, "->attr('name', 'quantity')") === 2
        && str_contains($v, "__t('ecommerce.cart.decrease')") && str_contains($v, "__t('ecommerce.cart.increase')")
        && !str_contains($v, "__t('ecommerce.cart.update')")
        && str_contains($v, 'bi-trash3')
        && str_contains($v, '--wi-thumb-size: 88px')
        && str_contains($v, "'coupons' => false")
        && str_contains($v, "Accordion::make((string) __t('ecommerce.checkout.coupon_title'))")
        // Il tema Wonder non rende `Text`/`RichText`: il form entra nell'accordion come HTML già pronto.
        && !str_contains($v, 'RichText')
        && str_contains($v, 'components/checkout/coupon.php');
});

check('«Rimuovi» e il − a quantità 1 chiedono conferma; il − a 1 manda alla rimozione', function () use ($root): bool {
    $v = (string) file_get_contents($root.'/view/pages/cart/index.php');
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);
    $keys = ['remove_confirm_title', 'remove_confirm_text', 'remove_confirm_ok'];
    $lang = array_reduce($keys, fn ($ok, $k) => $ok && trim((string) ($it['cart'][$k] ?? '')) !== '' && trim((string) ($en['cart'][$k] ?? '')) !== '', true);

    return $lang
        && str_contains($it['cart']['remove_confirm_text'] ?? '', '{{name}}')
        && str_contains($v, 'data-wi-confirm=')
        && str_contains($v, "->attr('formaction', \$remove)")
        && str_contains($v, '->confirm(...$confirm)')
        // Il − a 1 non è più spento: apre la conferma.
        && !str_contains($v, '->disabled($quantity <= 1)')
        && str_contains($v, 'data-cart-action');
});

check('le operazioni del carrello mostrano lo spinner per un tempo minimo e lo tolgono tornando indietro', function () use ($root): bool {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return str_contains($js, "form[data-cart-action]")
        && str_contains($js, 'loadingSpinner(on)')
        && str_contains($js, 'cartSpinner(true)') && str_contains($js, 'cartSpinner(false)')
        && (bool) preg_match('/CART_SPINNER_MIN\s*=\s*\d{3,4}/', $js)
        // Il bottone premuto porta quantità e formaction: form.submit() li perderebbe.
        && str_contains($js, 'form.requestSubmit(submitter)')
        && str_contains($js, "'pageshow'");
});

check('con un solo articolo il titolo del carrello dice «1 articolo»', function () use ($root): bool {
    $v = (string) file_get_contents($root.'/view/pages/cart/index.php');
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);

    return ($it['cart']['count_one'] ?? '') === '1 articolo'
        && ($en['cart']['count_one'] ?? '') === '1 item'
        && str_contains($v, "'ecommerce.cart.count_one'");
});

summary();
