<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
?>
<form id="<?=e($id)?>" class="mt-5" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <?=FormField::key('return')->hidden()->value($return)?>
    <div class="d-grid col-2 gap-3">
        <?=FormField::key('code')->text()->label((string) __t('ecommerce.checkout.coupon_label'))->value($code)?>
        <div class="d-flex gap-3 a-end">
            <?=Button::make((string) __t('ecommerce.checkout.coupon_apply'))->type('submit')->variant('secondary')->attr('name', 'action')->attr('value', 'apply')?>
            <?php $remove = Button::make((string) __t('ecommerce.checkout.coupon_remove'))->type('submit')->variant('secondary')->attr('name', 'action')->attr('value', 'remove')->attr('data-checkout-coupon-remove', ''); ?>
            <?=$code === '' ? $remove->attr('hidden', 'hidden') : $remove?>
        </div>
    </div>
</form>
