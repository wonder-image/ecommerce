<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;
?>
<details class="wi-box p-4 mb-4 pc-none">
    <summary class="d-flex gap-3">
        <span class="w-100"><?=e(__t('ecommerce.checkout.show_cart'))?></span>
        <strong data-checkout-total><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
    </summary>
    <div class="mt-4">
        <?=View::component(Ecommerce::viewPath('components/checkout/lines.php'), compact('items', 'currency'))?>
        <?=View::component(Ecommerce::viewPath('components/checkout/totals.php'), ['order' => $order, 'currency' => $currency, 'shippingRow' => Gestionale::feature('shipping') && (string) ($order['fulfillment_type'] ?? 'shipping') !== 'pickup'])?>
        <?php if ($coupons): ?>
            <?=View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['csrf_token' => $csrf_token, 'code' => $couponCode, 'return' => $step === 'payment' ? 'payment' : 'checkout', 'id' => 'checkout-coupon-mobile'])?>
        <?php endif; ?>
    </div>
</details>
