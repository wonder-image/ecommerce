<?php

use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Frontend\Account\AccountPresenter;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('account', compact('title', 'errors', 'notice') + ['active' => 'shipping']);
?>
<div class="d-flex f-end mb-5">
    <?=Button::to(__r('ecommerce.account.shipping.create'), (string) __t('ecommerce.account.shipping.add'))->icon('bi bi-plus-lg')->render()?>
</div>
<?php if ($addresses === []): ?>
    <div class="p-4 bg-primary-10 b-r-10"><p class="text"><?=e(__t('ecommerce.account.shipping.empty'))?></p></div>
<?php else: ?>
    <div class="d-grid col-1 gap-4">
        <?php foreach ($addresses as $address): ?>
            <?=View::component(Ecommerce::viewPath('components/account/row.php'), [
                'label' => AccountPresenter::shippingLabel($address),
                'value' => AccountPresenter::addressLines($address),
                'href' => __r('ecommerce.account.shipping.edit', ['id' => (int) ($address['id'] ?? 0)]),
                'action' => (string) __t('ecommerce.account.actions.edit'),
            ])?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
