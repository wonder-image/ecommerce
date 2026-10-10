<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;

$quantity = max(1, (float) ($quantity ?? 1));
$continue = (string) ($continue ?? ($_SERVER['REQUEST_URI'] ?? '/cart/'));
$csrf_token ??= AuthSession::csrfToken();
$stepper = (bool) ($stepper ?? false);
?>
<form id="add_to_cart_<?=e((string) $product_id)?>" method="post" action="<?=e(__r('ecommerce.cart.add'))?>" data-ecommerce-add-to-cart>
    <?=FormField::key('csrf_token')->hidden()->value((string) $csrf_token)?>
    <?=FormField::key('product_id')->hidden()->value((string) $product_id)?>
    <?=FormField::key('continue')->hidden()->value($continue)?>
    <?php if ($stepper): ?>
        <div class="w-100 d-flex gap-3">
            <?=View::component(Ecommerce::viewPath('components/cart/quantity.php'), ['quantity' => $quantity])?>
            <button class="btn btn-primary w-100" type="submit"><?=e((string) ($label ?? __t('ecommerce.cart.add')))?></button>
        </div>
    <?php else: ?>
        <?=FormField::key('quantity')->hidden()->value((string) $quantity)?>
        <button class="btn btn-primary w-100" type="submit"><?=e((string) ($label ?? __t('ecommerce.cart.add')))?></button>
    <?php endif; ?>
</form>
