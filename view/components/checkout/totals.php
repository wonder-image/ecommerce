<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$row = static fn (string $label, mixed $amount, bool $strong = false): string => '<div class="d-grid col-2 gap-3"><span'.($strong ? ' class="fw-700"' : '').'>'.e($label).'</span>'
    .($strong ? '<strong class="a-r">' : '<span class="a-r">').e(CartPresenter::money($amount, $currency)).($strong ? '</strong>' : '</span>').'</div>';
?>
<div class="d-grid col-1 gap-3 mt-5 pt-4" data-checkout-totals>
    <?=$row((string) __t('ecommerce.cart.products_total'), $order['products_total'] ?? 0)?>
    <?php if ((float) ($order['discount_total'] ?? 0) !== 0.0): ?><?=$row((string) __t('ecommerce.cart.discount'), -abs((float) $order['discount_total']))?><?php endif; ?>
    <?php if ($shippingRow): ?><?=$row((string) __t('ecommerce.checkout.shipping_total'), $order['shipping_total'] ?? 0)?><?php endif; ?>
    <?php if ((float) ($order['fees_total'] ?? 0) !== 0.0): ?><?=$row((string) __t('ecommerce.checkout.fees_total'), $order['fees_total'])?><?php endif; ?>
    <?=$row((string) __t('ecommerce.cart.total'), $order['total'] ?? 0, true)?>
</div>
