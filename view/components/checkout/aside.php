<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Slots;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$step = (string) ($step ?? 'checkout');
$withLines = $step !== 'cart';
$express = (string) ($express ?? '');
$shippingFrom = $step === 'cart' ? CartPresenter::shippingFrom((array) $order) : null;
// Lo slot esce sotto la riga «Spedizione da …», quindi solo quando c'è.
$afterShipping = $shippingFrom !== null ? Slots::render('cart.after_shipping', compact('order', 'items', 'currency')) : '';

?>
<aside class="<?=$withLines ? 'w-100 col-2 col-t-1 wi-checkout__aside' : 'wi-box h-auto p-5'?>"<?=$withLines ? ' data-checkout-aside' : ''?>>

    <?php // Nel carrello il riepilogo è solo totali: titolo e righe stanno vicini. ?>
    <h2 class="subtitle <?=$withLines ? 'mb-4' : 'mb-3'?>"><?=e(__t($withLines ? 'ecommerce.checkout.your_cart' : 'ecommerce.cart.summary'))?></h2>
    
    <?php if ($withLines): ?><?=View::component(Ecommerce::viewPath('components/checkout/lines.php'), compact('items', 'currency'))?><?php endif; ?>
    
    <?php if ($coupons): ?>
        <?=View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['code' => $couponCode, 'return' => $step === 'cart' ? 'cart' : 'checkout', 'id' => 'checkout-coupon'])?>
    <?php endif; ?>

    <div class="w-100<?=$withLines ? ' mt-1' : ''?>" data-checkout-notices>
        <?php foreach ($notices as $message): ?><p class="text-small" role="status"><?=e($message)?></p><?php endforeach; ?>
    </div>

    <?=View::component(Ecommerce::viewPath('components/checkout/totals.php'), ['order' => $order, 'currency' => $currency, 'shippingRow' => $step !== 'cart' && Gestionale::feature('shipping') && (string) ($order['fulfillment_type'] ?? 'shipping') !== 'pickup', 'shippingFrom' => $shippingFrom, 'afterShipping' => $afterShipping, 'spacing' => $withLines ? 'mt-5 pt-4' : ''])?>

    <?php if ($shippingFrom > 0): ?><p class="text-small mt-2"><?=e(__t('ecommerce.cart.shipping_excluded'))?></p><?php endif; ?>

    <?=$express?>

    <?=$button?>

</aside>
