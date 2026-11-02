<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;
?>
<details class="wi-box p-4 mb-4 pc-none wi-checkout__mobile">
    <summary class="d-flex gap-3" style="align-items: center">
        <span class="w-100 d-flex gap-2" style="align-items: center"><?=e(__t('ecommerce.checkout.order_summary'))?> <i class="bi bi-chevron-down" aria-hidden="true"></i></span>
        <strong data-checkout-total><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
    </summary>
    <div class="w-100 mt-4">
        <?=View::component(Ecommerce::viewPath('components/checkout/lines.php'), compact('items', 'currency'))?>
        <?=View::component(Ecommerce::viewPath('components/checkout/totals.php'), ['order' => $order, 'currency' => $currency, 'shippingRow' => Gestionale::feature('shipping') && (string) ($order['fulfillment_type'] ?? 'shipping') !== 'pickup'])?>
        <?php if ($coupons): ?>
            <?=View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['code' => $couponCode, 'return' => 'checkout', 'id' => 'checkout-coupon-mobile', 'order' => $order, 'currency' => $currency])?>
        <?php endif; ?>
    </div>
</details>
