<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

$active ??= 'shipping';
Ecommerce::layout('account', compact('title', 'errors', 'notice', 'active'));
?>
<?php if (!empty($intro)): ?><p class="text mb-5"><?=e($intro)?></p><?php endif; ?>
<div>
    <form id="<?=e($active === 'billing' ? 'update_billing_address' : 'save_shipping_address')?>" method="post" class="d-grid col-2 col-p-1 gap-4" novalidate>
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <?php foreach ((array) $fields as $field): ?>
            <?=$field?>
        <?php endforeach; ?>
        <div class="d-flex gap-3 col-2 col-p-1">
            <button class="btn btn-primary" type="submit"><?=e(__t('ecommerce.account.actions.save'))?></button>
            <?=Button::to(__r('ecommerce.account.index'), (string) __t('ecommerce.account.actions.cancel'))->outline()->render()?>
        </div>
    </form>
</div>
<?php View::end(); ?>
