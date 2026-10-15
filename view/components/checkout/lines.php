<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$line = static function (array $item) use ($currency): string {
    $image = trim((string) ($item['image'] ?? ''));
    $parts = (array) ($item['parts'] ?? CartPresenter::parts($item));

    return '<div class="w-100 d-flex gap-4">'
        .'<span class="wi-thumb" style="--wi-thumb-size: 64px">'
        .($image === '' ? '' : '<img src="'.e($image).'" alt="" loading="lazy" data-line-image>')
        .'<span class="badge badge-dark" data-line-quantity>'.e($item['quantity_display'] ?? CartPresenter::quantity($item['quantity'] ?? 1)).'</span></span>'
        .'<span class="w-100"><span class="d-block" data-line-name>'.e($item['name'] ?? '').'</span>'
        .'<ul class="text-small mt-1" data-line-parts'.($parts === [] ? ' hidden' : '').'>'.implode('', array_map(
            static fn (string $part): string => '<li>'.e($part).'</li>',
            $parts
        )).'</ul>'
        .'</span>'
        .'<span class="a-r" data-line-total>'.e($item['line_total_display'] ?? CartPresenter::money($item['line_total'] ?? 0, $currency)).'</span>'
        .'</div>';
};
?>
<div class="w-100 d-grid col-1 gap-4" data-checkout-lines>
    <?php foreach ($items as $item): ?><?=$line($item)?><?php endforeach; ?>
</div>
<template data-checkout-line><?=$line(['name' => '', 'quantity_display' => '', 'line_total_display' => '', 'image' => 'data:,'])?></template>
