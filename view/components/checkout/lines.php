<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$line = static function (array $item) use ($currency): string {
    $image = trim((string) ($item['image'] ?? ''));
    $sku = trim((string) ($item['sku'] ?? ''));

    return '<div class="d-flex gap-4">'
        .'<span class="wi-thumb" style="--wi-thumb-size: 56px">'
        .($image === '' ? '' : '<img src="'.e($image).'" alt="" loading="lazy" data-line-image>')
        .'<span class="badge badge-dark" data-line-quantity>'.e($item['quantity_display'] ?? CartPresenter::quantity($item['quantity'] ?? 1)).'</span></span>'
        .'<span class="w-100"><span class="d-block fw-600" data-line-name>'.e($item['name'] ?? '').'</span>'
        .'<span class="d-block text-small tx-secondary" data-line-sku'.($sku === '' ? ' hidden' : '').'>'.e($sku === '' ? '' : (string) __t('ecommerce.cart.sku', ['sku' => $sku])).'</span></span>'
        .'<span class="fw-600 a-r" data-line-total>'.e($item['line_total_display'] ?? CartPresenter::money($item['line_total'] ?? 0, $currency)).'</span>'
        .'</div>';
};
?>
<div class="d-grid col-1 gap-4" data-checkout-lines>
    <?php foreach ($items as $item): ?><?=$line($item)?><?php endforeach; ?>
</div>
<template data-checkout-line><?=$line(['name' => '', 'quantity_display' => '', 'line_total_display' => '', 'image' => 'data:,'])?></template>
