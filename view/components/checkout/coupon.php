<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
?>
<form id="<?=e($id)?>" class="p-r f-start w-100 mt-5" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
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
