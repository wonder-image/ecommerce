<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$step = (string) ($step ?? 'checkout');
$withLines = $step !== 'cart';

?>
<aside class="<?=$withLines ? 'w-100 wi-checkout__aside' : 'wi-box h-auto p-5'?>"<?=$withLines ? ' data-checkout-aside' : ''?>>

    <h2 class="subtitle mb-4"><?=e(__t($withLines ? 'ecommerce.checkout.your_cart' : 'ecommerce.cart.summary'))?></h2>
    
    <?php if ($withLines): ?><?=View::component(Ecommerce::viewPath('components/checkout/lines.php'), compact('items', 'currency'))?><?php endif; ?>
    
    <?php if ($coupons): ?>
        <?=View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['csrf_token' => $csrf_token, 'code' => $couponCode, 'return' => $step === 'cart' ? 'cart' : 'checkout', 'id' => 'checkout-coupon'])?>
    <?php endif; ?>

    <?=View::component(Ecommerce::viewPath('components/checkout/totals.php'), ['order' => $order, 'currency' => $currency, 'shippingRow' => $step !== 'cart' && Gestionale::feature('shipping') && (string) ($order['fulfillment_type'] ?? 'shipping') !== 'pickup'])?>

    <div class="w-100 mt-1" data-checkout-notices>
        <?php foreach ($notices as $message): ?><p class="text-small" role="status"><?=e($message)?></p><?php endforeach; ?>
    </div>

    <?=$button?>

</aside>
