<?php
/** php tests/CheckoutStripeTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;

$root = dirname(__DIR__);
$js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
$view = (string) file_get_contents($root.'/view/pages/checkout/index.php');
$summary = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutSummary.php');
$pay = (string) file_get_contents($root.'/view/pages/checkout/pay.php');
$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

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
    $submit = strpos($js, 'await elements.submit()');
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

check('la vista ha il posto per la carta e l\'indirizzo per riaprire il modulo, senza «Annulla l\'ordine»', fn () => str_contains($view, 'data-checkout-stripe hidden')
    && str_contains($view, 'data-checkout-stripe-element')
    && str_contains($view, 'data-checkout-stripe-notice')
    && str_contains($view, "data-reopen-url=\"<?=e(__r('ecommerce.checkout.reopen'))?>\"")
    && !str_contains($view, 'abandon')
    && str_contains($view, "\$labels['stripe_error']")
    && str_contains($view, "\$labels['pay_failed']"));

check('dopo un rifiuto il modulo si riapre: l\'ordine si annulla e si può cambiare metodo', function () use ($js): bool {
    $reopen = substr($js, (int) strpos($js, '    async reopen(keep = []) {'), 1200);

    return !str_contains($js, 'abandon')
        && substr_count($js, 'await this.reopen(') === 2
        && str_contains($reopen, 'if (!this.placed)')
        && str_contains($reopen, 'this.placed = null;')
        && str_contains($reopen, 'this.frozen = false;')
        && str_contains($reopen, '(this.frozenFields || []).forEach((field) => { field.disabled = false; });')
        && str_contains($reopen, 'this.resetRecaptcha();')
        && str_contains($reopen, 'this.request(this.root.dataset.reopenUrl, this.body())')
        && str_contains($reopen, 'this.render(payload)')
        && str_contains($js, '.filter((field) => !field.disabled)');
});

check('la pagina «Paga ora» offre «Cambia metodo di pagamento», che riapre il checkout', function () use ($pay, $root): bool {
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');

    return str_contains($pay, "action=\"<?=e(__r('ecommerce.checkout.reopen'))?>\"")
        && str_contains($pay, "__t('ecommerce.checkout.pay.change_method')")
        && !str_contains($pay, 'abandon')
        && str_contains($routes, "Route::post('/reopen/', \$handler, ['checkout_action' => 'reopen'])->name('reopen')")
        && !str_contains($routes, 'abandon')
        && ($it['checkout']['pay']['change_method'] ?? '') === 'Cambia metodo di pagamento'
        && ($en['checkout']['pay']['change_method'] ?? '') === 'Change payment method'
        && str_contains((string) ($it['checkout']['pay']['removed'] ?? ''), '{{names}}')
        && str_contains((string) ($en['checkout']['pay']['removed'] ?? ''), '{{names}}')
        && !isset($it['checkout']['pay']['abandon'], $it['checkout']['pay']['abandoned'], $en['checkout']['pay']['abandon'], $en['checkout']['pay']['abandoned']);
});

check('il riepilogo dà al browser solo chiavi pubbliche, centesimi e i tipi che il gestionale dà alla scelta', fn () => str_contains($summary, 'OnlinePayment::browserKeys()')
    && str_contains($summary, "'amount' => (int) round((float) \$preview['order']['total'] * 100)")
    && !str_contains($summary, 'StripeProvider')
    && str_contains($summary, "\$option['payment_method_types']")
    && str_contains($summary, "'payment_method_types' =>")
    && str_contains($summary, "(\$option['provider'] ?? '') === 'stripe'")
    && !str_contains($summary, 'stripe_private_key')
    && !str_contains($summary, 'stripe_test_key'));

check('nel Payment Element niente Link né wallet, e niente dati del cliente: solo i campi della carta', fn () => str_contains($js, "wallets: { applePay: 'never', googlePay: 'never', link: 'never' }")
    && str_contains($js, "fields: { billingDetails: 'never' }")
    && substr_count($js, "create('payment', STRIPE_PAYMENT_ELEMENT)") === 2
    && !str_contains($js, "create('payment')"));

check('i dati del cliente arrivano a Stripe dall\'ordine, al checkout e su «Paga ora»', fn () => str_contains($js, 'payment_method_data: { billing_details: this.placed.billing_details')
    && str_contains($js, 'payment_method_data: { billing_details: this.billing')
    && str_contains($js, 'this.root.dataset.billingDetails')
    && str_contains($pay, 'data-billing-details=')
    && str_contains($controller, "'billing_details' => OnlinePayment::billingDetails(")
    && str_contains($controller, "'billing_details' => OnlinePayment::billingDetails(\$order)"));

check('i campi della carta stanno nel pannello della scelta e sopravvivono al riepilogo', function () use ($js): bool {
    $choices = substr($js, (int) strpos($js, '    choices(container'), 2400);

    return str_contains($choices, 'same')
        && str_contains($choices, 'group.replaceChildren(')
        && str_contains($js, "closest('label')?.querySelector('[data-choice-panel]')")
        && str_contains($js, 'this.paymentElement.unmount()')
        && str_contains($js, 'this.paymentElement.mount(')
        && str_contains($js, '[data-checkout-stripe-element]');
});

check('ogni scelta Stripe ha il suo gruppo elements, con i soli tipi della scelta', fn () =>
    str_contains($js, 'this.groups')
    && str_contains($js, 'paymentMethodTypes: chosen.payment_method_types')
    && str_contains($js, "'loaderror'")
    && str_contains($js, 'dropChoice('));

check('il radio del pagamento vale la chiave della scelta', fn () =>
    str_contains($js, 'value: o.key') && !str_contains(substr($js, (int) strpos($js, '    payments(payload) {'), 900), 'value: o.id'));

$dati = OnlinePayment::billingDetails([
    'email' => 'mario@example.com',
    'phone' => '',
    'billing_type' => 'private',
    'billing_name' => 'Mario',
    'billing_surname' => 'Rossi',
    'billing_business_name' => '',
    'billing_country' => 'it',
    'billing_province' => 'MI',
    'billing_city' => 'Milano',
    'billing_cap' => '20100',
    'billing_street' => 'Via Roma',
    'billing_number' => '1',
    'billing_more' => 'Scala B',
    'billing_phone_prefix' => '+39',
    'billing_phone' => '333 1234567',
]);

check('billingDetails prende nome, email, telefono e indirizzo della fatturazione', fn () => $dati === [
    'name' => 'Mario Rossi',
    'email' => 'mario@example.com',
    'phone' => '+39 333 1234567',
    'address' => [
        'line1' => 'Via Roma 1',
        'line2' => 'Scala B',
        'city' => 'Milano',
        'state' => 'MI',
        'postal_code' => '20100',
        'country' => 'IT',
    ],
]);

check('senza fatturazione billingDetails usa destinatario e indirizzo della consegna, con tutti i campi', fn () => OnlinePayment::billingDetails([
    'email' => 'anna@example.com',
    'phone' => '3330000000',
    'billing_country' => 'IT',
    'billing_name' => '',
    'shipping_name' => 'Anna',
    'shipping_surname' => 'Bianchi',
    'shipping_country' => 'FR',
    'shipping_city' => 'Paris',
    'shipping_cap' => '75001',
    'shipping_street' => 'Rue de Rivoli',
    'shipping_number' => '10',
]) === [
    'name' => 'Anna Bianchi',
    'email' => 'anna@example.com',
    'phone' => '3330000000',
    'address' => [
        'line1' => 'Rue de Rivoli 10',
        'line2' => '',
        'city' => 'Paris',
        'state' => '',
        'postal_code' => '75001',
        'country' => 'FR',
    ],
]);

check('per un\'azienda il nome è la ragione sociale; senza indirizzi resta il paese', fn () => OnlinePayment::billingDetails([
    'email' => 'info@example.com',
    'billing_type' => 'business',
    'billing_business_name' => 'Rossi Srl',
    'billing_name' => 'Mario',
    'billing_country' => '',
])['name'] === 'Rossi Srl'
    && OnlinePayment::billingDetails(['billing_country' => ''])['address']['country'] === 'IT');

check('mentre si paga lo spinner della lib dice «Elaborazione pagamento» e torna com\'era dopo', function () use ($js, $view, $pay, $root): bool {
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);

    return ($it['checkout']['pay']['processing'] ?? '') === 'Elaborazione pagamento'
        && ($en['checkout']['pay']['processing'] ?? '') === 'Processing payment'
        && str_contains($view, "\$labels['processing'] = (string) __t('ecommerce.checkout.pay.processing')")
        && str_contains($pay, "'processing' => (string) __t('ecommerce.checkout.pay.processing')")
        && str_contains($js, 'function paySpinner(')
        && substr_count($js, 'paySpinner(true, this.labels.processing)') === 2
        && substr_count($js, 'paySpinner(false)') >= 3
        && str_contains($js, '#loading-spinner .text');
});

check('il rifiuto di Stripe (anche del 3DS) arriva come alert della lib, che resta finché non lo chiudi', function () use ($js, $view, $pay, $root): bool {
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);
    $template = "<template data-checkout-pay-alert><?=alertTheme('custom', 'error', (string) __t('ecommerce.checkout.pay.alert_title'), '')?></template>";

    return ($it['checkout']['pay']['alert_title'] ?? '') === 'Pagamento non riuscito'
        && ($en['checkout']['pay']['alert_title'] ?? '') === 'Payment failed'
        && str_contains($view, $template) && str_contains($pay, $template)
        && str_contains($js, 'function payAlert(')
        && str_contains($js, 'alertContainer()')
        && str_contains($js, "'.wi-alert-body'")
        && str_contains($js, 'payAlert(error.message || this.labels.pay_failed)')
        && str_contains($js, 'payAlert(error.message || this.labels.failed)')
        && substr_count($js, "payAlert('')") === 2;
});

check('il rifiuto di Stripe sta solo nell\'alert: niente scritta sotto il box del pagamento', function () use ($js): bool {
    return !str_contains($js, 'this.say(payAlert(')
        && !str_contains($js, 'this.say([message])')
        && !str_contains($js, 'this.reopen([message])')
        && str_contains($js, 'payAlert(error.message || this.labels.pay_failed);')
        && str_contains($js, 'payAlert(error.message || this.labels.failed);');
});

check('il radio del pagamento vale la chiave della scelta; post la spezza in id e tipo', fn () =>
    str_contains($view, "Choice::make('payment_method_id', (string) \$p['key'])")
    && \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment('891:klarna') === [891, 'klarna']
    && \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment('891') === [891, '']
    && \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment('x:y') === [0, '']);

check('«Paga» fissa il gruppo all\'inizio e usa quell\'elements fino a confirmPayment; durante il pagamento la scelta non cambia', function () use ($js): bool {
    $from = (int) strpos($js, '    async payOnline() {');
    $pay = substr($js, $from, (int) strpos($js, '    async place() {') - $from);
    $drop = substr($js, (int) strpos($js, '    dropChoice(key) {'), 160);
    $schedule = substr($js, (int) strpos($js, '    schedule() {'), 600);

    return str_contains($pay, 'const { elements } = group;')
        && str_contains($pay, 'await elements.submit()')
        && (bool) preg_match('/confirmPayment\(\{\s*elements,/', $pay)
        && !str_contains($pay, 'this.elements')
        && !str_contains($pay, 'this.paymentElement')
        && str_contains($pay, 'this.payKey = ')
        && str_contains($js, 'syncPaymentRadios()')
        && str_contains($js, 'radio.disabled = Boolean(this.paying) || Boolean(this.frozen)')
        && str_contains($js, "if (this.payKey) {\n            data.set('payment_method_id', this.payKey);")
        && (bool) preg_match('/^\s*dropChoice\(key\) \{\s*(\/\/[^\n]*\s*)?if \(this\.paying\)/', $drop)
        && (bool) preg_match('/if \(this\.frozen\) \{\s*return;\s*\}\s*(\/\/[^\n]*\s*)?if \(this\.paying\) \{\s*return;/', $schedule);
});

check('il riquadro dei metodi a reindirizzamento è senza bordo né sfondo', fn () =>
    str_contains($js, "const STRIPE_APPEARANCE = { rules: { '.Block': { border: 'none', boxShadow: 'none', padding: '0', backgroundColor: 'transparent' }, '.BlockDivider': { backgroundColor: 'transparent' } } };")
    && str_contains($js, 'appearance: STRIPE_APPEARANCE')
    && !str_contains($js, '// PROVA'));

check('nessuna scelta mostra i wallet: Link, Apple Pay e Google Pay stanno nella barra rapida', fn () =>
    !str_contains($js, "link: 'auto'")
    && str_contains($js, "elements.create('payment', STRIPE_PAYMENT_ELEMENT)")
    && str_contains($js, "const STRIPE_PAYMENT_ELEMENT = { wallets: { applePay: 'never', googlePay: 'never', link: 'never' }"));

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;

check('dopo un rifiuto con redirect lo stato del modulo ricorda il valore grezzo del radio', function (): bool {
    $post = CheckoutRules::post(['payment_method_id' => '891:klarna', 'email' => 'a@example.com']);
    $kept = CheckoutRules::rememberedPost($post, ['payment_method_id' => '891:klarna']);
    $card = CheckoutRules::rememberedPost(CheckoutRules::post(['payment_method_id' => '891']), ['payment_method_id' => '891']);

    return $post['payment_method_id'] === 891 && $post['stripe_method_type'] === 'klarna'
        && $kept['payment_method_id'] === '891:klarna' && !array_key_exists('stripe_method_type', $kept)
        && $kept['email'] === 'a@example.com'
        && $card['payment_method_id'] === '891'
        && CheckoutRules::rememberedPost(['payment_method_id' => 7], [])['payment_method_id'] === 7
        && CheckoutRules::rememberedPost(['payment_method_id' => 7], ['payment_method_id' => ['x']])['payment_method_id'] === 7
        // Al riepilogo e al carrello torna l'id nudo.
        && CheckoutRules::post(['payment_method_id' => $kept['payment_method_id']])['payment_method_id'] === 891;
});

check('nella vista è spuntata la scelta con la chiave salvata; senza corrispondenza vale l\'id più la carta', function () use ($view, $controller): bool {
    $options = [
        ['id' => 891, 'key' => '891', 'stripe_method_type' => 'card'],
        ['id' => 891, 'key' => '891:klarna', 'stripe_method_type' => 'klarna'],
        ['id' => 7, 'key' => '7', 'stripe_method_type' => ''],
    ];

    return CheckoutRules::checkedPayment($options, '891:klarna', 891) === '891:klarna'
        && CheckoutRules::checkedPayment($options, '891:link', 891) === '891'
        && CheckoutRules::checkedPayment($options, '891', 891) === '891'
        && CheckoutRules::checkedPayment($options, '', 7) === '7'
        && CheckoutRules::checkedPayment($options, '', 0) === ''
        && str_contains($view, 'CheckoutRules::checkedPayment(')
        && str_contains($controller, 'CheckoutRules::rememberedPost(');
});

check('i tipi della scelta arrivano dal gestionale, non dal CSV', fn () =>
    str_contains($summary, "'payment_method_types' =>")
    && !str_contains($summary, 'stripe_payment_method_types')
    && str_contains($controller, "'stripe_method_type' =>"));

check('splitPayment: tipo valido solo [a-z0-9_]{1,40}, id solo positivo, valori non scalari scartati', function (): bool {
    $split = fn (mixed $v): array => \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment($v);

    return $split(891) === [891, '']
        && $split('891:Klarna') === [891, '']
        && $split('891:bad-type') === [891, '']
        && $split('891:') === [891, '']
        && $split('891:'.str_repeat('a', 41)) === [891, '']
        && $split('891:'.str_repeat('a', 40)) === [891, str_repeat('a', 40)]
        && $split('891:us_bank_account') === [891, 'us_bank_account']
        && $split('891:klarna:x') === [891, '']
        && $split('0:klarna') === [0, '']
        && $split('-3') === [0, '']
        && $split('') === [0, '']
        && $split(['891']) === [0, '']
        && $split(null) === [0, ''];
});

summary();
