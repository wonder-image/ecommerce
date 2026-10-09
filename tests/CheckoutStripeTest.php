<?php
/** php tests/CheckoutStripeTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);
$js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
$view = (string) file_get_contents($root.'/view/pages/checkout/index.php');
$summary = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutSummary.php');

check('Stripe.js arriva da Stripe, una volta sola e solo quando serve', fn () => str_contains($js, "'https://js.stripe.com/v3/'")
    && str_contains($js, 'function loadStripe()')
    && substr_count($js, 'js.stripe.com') === 1);

check('il Payment Element del checkout parte senza intento, con importo e valuta del riepilogo', fn () => str_contains($js, "mode: 'payment'")
    && str_contains($js, 'this.elements.update(')
    && str_contains($js, 'stripeAccount')
    && str_contains($js, 'this.stripeBox(payload)')
    && str_contains($js, 'this.stripeBox(this.latest)')
    && str_contains($js, "chosen?.provider === 'stripe'"));

check('«Paga»: prima Stripe controlla la carta, poi nasce l\'ordine col totale visto, poi Stripe incassa', function () use ($js): bool {
    $submit = strpos($js, 'await this.elements.submit()');
    $place = strpos($js, 'await this.place()');
    $confirm = strpos($js, 'await this.stripe.confirmPayment(');

    return $submit !== false && $place !== false && $confirm !== false
        && $submit < $place && $place < $confirm
        && str_contains($js, 'expected_total')
        && str_contains($js, "'total_changed'")
        && str_contains($js, 'new URL(this.placed.return_url, window.location.href)');
});

check('un secondo «Paga» riusa l\'ordine già nato e il modulo non cambia più', fn () => str_contains($js, 'if (!this.placed)')
    && str_contains($js, 'this.freeze()')
    && (bool) preg_match('/this\.sequence\+\+;\s*clearTimeout\(this\.timer\);\s*if \(this\.frozen\)/', $js)
    && str_contains($js, 'grecaptcha'));

check('dopo un errore di place il reCAPTCHA Enterprise si rinnova e il token usato si svuota', fn () => str_contains($js, 'window.grecaptcha?.enterprise?.reset')
    && str_contains($js, 'grecaptcha.enterprise.reset()')
    && str_contains($js, 'input[name="g-recaptcha-token"]')
    && str_contains($js, 'input[name="g-recaptcha-action"]')
    && !str_contains($js, 'window.grecaptcha.reset'));

check('la pagina «Paga ora» monta il Payment Element dal client_secret', fn () => str_contains($js, 'class CheckoutPay')
    && str_contains($js, 'clientSecret: this.root.dataset.clientSecret')
    && str_contains($js, "document.querySelectorAll('[data-checkout-pay]')")
    && !str_contains($js, 'innerHTML'));

check('la vista ha il posto per la carta e l\'annullamento dell\'ordine già nato', fn () => str_contains($view, 'data-checkout-stripe hidden')
    && str_contains($view, 'data-checkout-stripe-element')
    && str_contains($view, 'data-checkout-stripe-notice')
    && str_contains($view, 'form="checkout-abandon"')
    && str_contains($view, 'id="checkout-abandon"')
    && str_contains($view, "\$labels['stripe_error']")
    && str_contains($view, "\$labels['pay_failed']"));

check('il riepilogo dà al browser solo chiavi pubbliche, centesimi e metodi della carta', fn () => str_contains($summary, 'OnlinePayment::browserKeys()')
    && str_contains($summary, "'amount' => (int) round((float) \$preview['order']['total'] * 100)")
    && str_contains($summary, 'StripeProvider::methodTypes(')
    && str_contains($summary, "'payment_method_types' =>")
    && str_contains($summary, "(\$option['provider'] ?? '') === 'stripe'")
    && !str_contains($summary, 'stripe_private_key')
    && !str_contains($summary, 'stripe_test_key'));

summary();
