<?php

use Wonder\Elements\Components\Button;
use Wonder\Auth\Frontend\AccountAddressModal;
use Wonder\Plugin\Ecommerce\Frontend\Account\AccountPresenter;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

$page_modals = [AccountAddressModal::make('shipping-new', (string) __t('ecommerce.account.shipping.create_title'), $address_forms[0],
    __r('ecommerce.account.shipping.create'), __r('ecommerce.account.shipping'), $csrf_token)];
foreach ($addresses as $address) {
    $id = (int) $address['id'];
    $page_modals[] = AccountAddressModal::make('shipping-'.$id, (string) __t('ecommerce.account.shipping.edit_title'), $address_forms[$id],
        __r('ecommerce.account.shipping.edit', ['id' => $id]), __r('ecommerce.account.shipping'), $csrf_token);
}
Ecommerce::layout('account', compact('title', 'errors', 'notice', 'page_modals') + ['active' => 'shipping']);
?>
<div class="d-flex f-end mb-5">
    <?=Button::to(__r('ecommerce.account.shipping.create'), (string) __t('ecommerce.account.shipping.add'))
        ->opensModal('shipping-new')->icon('bi bi-plus-lg')->render()?>
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
                'action_component' => Button::to(__r('ecommerce.account.shipping.edit', ['id' => (int) $address['id']]), (string) __t('ecommerce.account.actions.edit'))
                    ->opensModal('shipping-'.(int) $address['id'])
                    ->outline()->icon('bi bi-pencil')->size('sm'),
            ])?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
