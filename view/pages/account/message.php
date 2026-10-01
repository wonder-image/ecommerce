<?php

use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('account', compact('title', 'active'));
?>
<?=Alert::make((string) $message, 'warning')->title((string) __t('ecommerce.account.error_title'))->dismissible(false)->render()?>
<div class="mt-5">
    <?=Button::to(__r('ecommerce.account.index'), (string) __t('ecommerce.account.actions.back'))->outline()->render()?>
</div>
<?php View::end(); ?>
