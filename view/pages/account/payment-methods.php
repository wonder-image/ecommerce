<?php

use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('account', compact('title') + ['active' => 'payment-methods']);
?>
<div>
    <?=Alert::make(
        (string) __t($enabled ? 'ecommerce.account.payment_methods.ready' : 'ecommerce.account.payment_methods.pending'),
        $enabled ? 'info' : 'warning'
    )->title('Stripe')->dismissible(false)->render()?>
    <div class="mt-5">
        <?=Button::to(__r('ecommerce.account.index'), (string) __t('ecommerce.account.actions.back'))->outline()->render()?>
    </div>
</div>
<?php View::end(); ?>
