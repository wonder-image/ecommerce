<?php

use Wonder\Elements\Components\Steps;

$order = ['cart' => 'ecommerce.cart.index', 'shipping' => 'ecommerce.checkout.index', 'payment' => 'ecommerce.checkout.payment'];
$current = array_search($step, array_keys($order), true);
$steps = Steps::make((string) __t('ecommerce.checkout.steps.label'));

foreach (array_keys($order) as $i => $key) {
    $state = $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
    $steps->step((string) __t('ecommerce.checkout.steps.'.$key), $state === 'done' ? (string) __r($order[$key]) : null, $state);
}
?>
<div class="w-100 mb-6"><?=$steps?></div>
