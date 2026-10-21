<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
use Wonder\Auth\Frontend\AuthSession;

$quantity = max(1, (float) ($quantity ?? 1));
$continue = (string) ($continue ?? ($_SERVER['REQUEST_URI'] ?? '/carrello/'));
$csrf_token ??= AuthSession::csrfToken();
$stepper = (bool) ($stepper ?? false);
// Campi in più già resi (le scelte di un multiprodotto), prima del bottone.
$fields = (string) ($fields ?? '');
// Vuoto quando il form può ripetersi nella pagina, come nelle card.
$id = (string) ($id ?? 'add_to_cart_'.$product_id);
?>
<form<?=$id !== '' ? ' id="'.e($id).'"' : ''?> method="post" action="<?=e(__r('ecommerce.cart.add'))?>" data-ecommerce-add-to-cart>
    <?=FormField::key('csrf_token')->hidden()->value((string) $csrf_token)?>
    <?=FormField::key('product_id')->hidden()->value((string) $product_id)?>
    <?=FormField::key('continue')->hidden()->value($continue)?>
    <?=$fields?>
    <?php if ($stepper): ?>
        <div class="w-100 d-flex gap-3">
            <?=View::component(Ecommerce::viewPath('components/cart/quantity.php'), ['quantity' => $quantity])?>
            <button class="btn btn-primary w-100 wi-input-submit" type="submit"><?=e((string) ($label ?? __t('ecommerce.cart.add')))?></button>
        </div>
    <?php else: ?>
        <?=FormField::key('quantity')->hidden()->value((string) $quantity)?>
        <button class="btn btn-primary w-100 wi-input-submit" type="submit"><?=e((string) ($label ?? __t('ecommerce.cart.add')))?></button>
    <?php endif; ?>
</form>
