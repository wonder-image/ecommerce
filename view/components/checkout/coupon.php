<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Http\Csrf;
?>
<?php // Lo stacco da ciò che sta sopra: nell'accordion del carrello basta quello che il titolo aperto ha già. ?>
<form id="<?=e($id)?>" class="p-r f-start w-100 <?=e((string) ($spacing ?? 'mt-5'))?>" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
    <?=Csrf::field()?>
    <?=FormField::key('return')->hidden()->value($return)?>

    <div class="w-100 d-flex gap-3">

        <div class="w-100">
            <?=FormField::key('code')->text()->label((string) __t('ecommerce.checkout.coupon_label'))->value($code)?>
        </div>
        
        <?=Button::make((string) __t('ecommerce.checkout.coupon_apply'))->type('submit')->variant('black wi-input-submit')->attr('name', 'action')->attr('value', 'apply')?>
        <?php $remove = Button::make((string) __t('ecommerce.checkout.coupon_remove'))->type('submit')->variant('black wi-input-submit')->attr('name', 'action')->attr('value', 'remove')->attr('data-checkout-coupon-remove', ''); ?>
        <?=$code === '' ? $remove->attr('hidden', 'hidden') : $remove?>

    </div>

</form>
