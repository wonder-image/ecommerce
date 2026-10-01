<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;

$quantity = max(1, (float) ($quantity ?? 1));
$continue = (string) ($continue ?? ($_SERVER['REQUEST_URI'] ?? '/cart/'));
$csrf_token ??= AuthSession::csrfToken();
?>
<form id="add_to_cart_<?=e((string) $product_id)?>" method="post" action="<?=e(__r('ecommerce.cart.add'))?>">
    <?=FormField::key('csrf_token')->hidden()->value((string) $csrf_token)?>
    <?=FormField::key('product_id')->hidden()->value((string) $product_id)?>
    <?=FormField::key('quantity')->hidden()->value((string) $quantity)?>
    <?=FormField::key('continue')->hidden()->value($continue)?>
    <button class="btn btn-primary w-100" type="submit"><?=e((string) ($label ?? __t('ecommerce.cart.add')))?></button>
</form>
