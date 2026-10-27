<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$row = static fn (string $label, mixed $amount, bool $strong = false): string => '<div class="w-100 d-grid col-2 gap-3"><span'.($strong ? ' class="fw-700"' : '').'>'.e($label).'</span>'
    .($strong ? '<strong class="a-r">' : '<span class="a-r">').e(CartPresenter::money($amount, $currency)).($strong ? '</strong>' : '</span>').'</div>';
?>
<?php // Lo stacco dalle righe sopra: chi non ne ha (il carrello) passa `spacing` vuoto. ?>
<div class="w-100 d-grid col-1 gap-3 <?=e((string) ($spacing ?? 'mt-5 pt-4'))?>" data-checkout-totals>
    <?=$row((string) __t('ecommerce.cart.products_total'), $order['products_total'] ?? 0)?>
    <?php if ((float) ($order['discount_total'] ?? 0) !== 0.0): ?><?=$row((string) __t('ecommerce.cart.discount'), -abs((float) $order['discount_total']))?><?php endif; ?>
    <?php // Carrello: il metodo non è scelto, si mostra il più economico. Il JS del coupon tiene riga e slot (data-cart-shipping); `col-2` la allarga come le altre righe. ?>
    <?php if (($cartShipping ?? null) !== null): ?><div class="w-100 col-2" data-cart-shipping><div class="w-100 d-grid col-2 gap-3"><span><?=e($cartShipping['label'])?></span><span class="a-r" data-cart-shipping-price><?=e($cartShipping['price'] > 0 ? CartPresenter::money($cartShipping['price'], $currency) : __t('ecommerce.checkout.free'))?></span></div><?=$afterShipping ?? ''?></div><?php endif; ?>
    <?php if ($shippingRow): ?><?=$row((string) __t('ecommerce.checkout.shipping_total'), $order['shipping_total'] ?? 0)?><?php endif; ?>
    <?php if ((float) ($order['fees_total'] ?? 0) !== 0.0): ?><?=$row((string) __t('ecommerce.checkout.fees_total'), $order['fees_total'])?><?php endif; ?>
    <?=$row((string) __t('ecommerce.cart.total'), ($cartShipping ?? null) !== null ? $cartShipping['total'] : ($order['total'] ?? 0), true)?>
</div>
