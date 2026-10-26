<?php
/** php tests/CheckoutThrottleTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressController;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\TooManyOrders;
use Wonder\Plugin\Gestionale\Support\Throttle;

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
$placement = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutPlacement.php');
$session = (string) file_get_contents($root.'/src/Frontend/Cart/CartSession.php');

/** Il corpo di un metodo statico, privato o pubblico, fino al metodo dopo. */
function corpo(string $sorgente, string $metodo): string
{
    if (!preg_match('/(private|public) static function '.preg_quote($metodo, '/').'\\(/', $sorgente, $trovato, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $inizio = $trovato[0][1];
    $fine = preg_match('/(private|public) static function /', $sorgente, $dopo, PREG_OFFSET_CAPTURE, $inizio + 1) ? $dopo[0][1] : false;

    return substr($sorgente, $inizio, $fine === false ? null : $fine - $inizio);
}

// La classe è del gestionale (allowed/hit/attempt si provano lì): qui solo chiavi, limiti e chiamanti.
$guest = (string) file_get_contents($root.'/src/Frontend/Checkout/GuestCheckout.php');
check('i chiamanti importano Throttle del gestionale, non quello del negozio', function () use ($controller, $placement, $guest): bool {
    foreach ([$controller, $placement, $guest] as $sorgente) {
        if (!str_contains($sorgente, "use Wonder\\Plugin\\Gestionale\\Support\\Throttle;")
            || str_contains($sorgente, 'Ecommerce\\Support\\Throttle')) {
            return false;
        }
    }

    return true;
});

check('niente più alias Support\\Throttle del negozio: si usa la classe del gestionale', fn () =>
    !is_file($root.'/src/Support/Throttle.php')
    && !class_exists('Wonder\\Plugin\\Ecommerce\\Support\\Throttle'));

check('niente Model del negozio per i contatori: la tabella è gst_throttles del gestionale', fn () =>
    !is_file($root.'/src/Models/Throttle.php')
    && !class_exists('Wonder\\Plugin\\Ecommerce\\Models\\Throttle')
    && str_contains(Throttle::UPSERT, 'INSERT INTO gst_throttles '));

$coupon = corpo($controller, 'coupon');
$placed = corpo($placement, 'place');

check('coupon: il limite vale solo per «applica», si controlla prima del tentativo e conta solo i falliti', fn () =>
    str_contains($coupon, "\$action === 'apply'")
    && str_contains($coupon, 'self::couponAllowed(')
    && strpos($coupon, 'self::couponAllowed(') < strpos($coupon, 'CheckoutSummary::coupon(')
    && str_contains($coupon, 'self::couponFailed(')
    && strpos($coupon, 'self::couponFailed(') > strpos($coupon, 'CheckoutSummary::coupon('));

check('coupon: oltre il limite risponde 429 col riepilogo, così il JavaScript lo disegna', fn () =>
    str_contains($coupon, 'ecommerce.checkout.errors.too_many_coupons')
    && str_contains($coupon, 'CheckoutSummary::payload(')
    && str_contains($coupon, '429'));

check('coupon: si conta per indirizzo IP e per carrello', fn () =>
    str_contains(corpo($controller, 'couponAllowed'), "'coupon_ip'")
    && str_contains(corpo($controller, 'couponAllowed'), "'coupon_cart'")
    && str_contains(corpo($controller, 'couponFailed'), "'coupon_ip'")
    && str_contains(corpo($controller, 'couponFailed'), "'coupon_cart'"));

check('ordini: il limite per cliente sta in CheckoutPlacement, così vale anche per la barra rapida', fn () =>
    str_contains($placed, "Throttle::allowed('orders'")
    && strpos($placed, "Throttle::allowed('orders'") < strpos($placed, 'Checkout::place(')
    && str_contains($placed, "Throttle::hit('orders'")
    && strpos($placed, "Throttle::hit('orders'") > strpos($placed, 'Checkout::place(')
    // Due throw in place(): il limite per cliente e quello dell'ospite.
    && substr_count($placed, 'throw new TooManyOrders();') === 2
    && !str_contains($placed, "RuntimeException((string) __t('ecommerce.checkout.errors.too_many_orders'))"));

// TooManyOrders legge il messaggio dal lang: qui basta la chiave.
if (!function_exists('__t')) {
    function __t(string $key): string
    {
        return $key;
    }
}

check('ordini: oltre il limite la barra rapida risponde rejected col messaggio e in più reason too_many_orders', function (): bool {
    $limite = ExpressController::rejected(new TooManyOrders());
    $altro = ExpressController::rejected(new RuntimeException('altro rifiuto'));

    return (new TooManyOrders()) instanceof RuntimeException
        && $limite === ['success' => false, 'error' => 'rejected', 'message' => (string) __t('ecommerce.checkout.errors.too_many_orders'), 'reason' => 'too_many_orders']
        && $altro === ['success' => false, 'error' => 'rejected', 'message' => 'altro rifiuto'];
});

$express = (string) file_get_contents($root.'/src/Frontend/Checkout/ExpressController.php');
check('ordini: place manda ogni UserError/RuntimeException da rejected() con 422', fn () =>
    str_contains(corpo($express, 'place'), "catch (UserError|RuntimeException \$error) {\n            self::json(self::rejected(\$error), 422);")
    && !str_contains($express, "'error' => 'too_many_orders'"));

$configurazione = require $root.'/config/module.php';
check('config: checkout.guest_order_limit di default ip [10, 900] e cart [5, 600]', fn () =>
    ($configurazione['checkout']['guest_order_limit'] ?? null) === ['ip' => [10, 900], 'cart' => [5, 600]]);

check('ordini: l\'ospite passa da attempt() per IP e carrello prima di account e Checkout::place', fn () =>
    str_contains($placed, 'self::guestAllowed($cartId)')
    && strpos($placed, 'self::guestAllowed($cartId)') < strpos($placed, 'GuestCheckout::account(')
    && strpos($placed, 'self::guestAllowed($cartId)') < strpos($placed, 'Checkout::place(')
    && str_contains(corpo($placement, 'guestAllowed'), "Throttle::attempt('orders'")
    && str_contains(corpo($placement, 'guestAllowed'), "'order_ip:'")
    && str_contains(corpo($placement, 'guestAllowed'), "'order_cart:'")
    && str_contains(corpo($placement, 'guestLimit'), "'checkout.guest_order_limit.'"));

check('ordini: il limite dell\'ospite si legge dalla config, 0 lo spegne', function (): bool {
    $ip = \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutPlacement::guestLimit('ip');
    $cart = \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutPlacement::guestLimit('cart');
    $spento = \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutPlacement::guestLimit('nessuno');

    return $ip === [10, 900] && $cart === [5, 600] && $spento === [0, 0] && Throttle::attempt('orders', 'x', $spento[0], $spento[1]);
});

check('carrello: in HTTPS il cookie è __Host- e il nome semplice non si legge', fn () =>
    str_contains($session, "'__Host-ecommerce_cart'")
    && str_contains(corpo($session, 'token'), 'self::cookieName()')
    && !str_contains(corpo($session, 'token'), '$_COOKIE[self::COOKIE]')
    && str_contains(corpo($session, 'cookieName'), 'self::secureCookie()'));

foreach (['it', 'en'] as $lingua) {
    $testi = json_decode((string) file_get_contents($root.'/lang/'.$lingua.'/ecommerce.json'), true);
    check("lang/{$lingua}: ci sono i due messaggi dei limiti", fn () =>
        ($testi['checkout']['errors']['too_many_coupons'] ?? '') !== ''
        && ($testi['checkout']['errors']['too_many_orders'] ?? '') !== '');
}

summary();
