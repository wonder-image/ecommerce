<?php

use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Button;
use Wonder\Http\Route;
use Wonder\View\View;

$title ??= '';
$active ??= 'personal';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<div>
    <?=Alert::make(
        (string) __t($enabled ? 'ecommerce.account.payment_methods.ready' : 'ecommerce.account.payment_methods.pending'),
        $enabled ? 'info' : 'warning'
    )->title('Stripe')->dismissible(false)->render()?>
    <div class="mt-5">
        <?=Button::to(Route::url('account.personal'), (string) __t('account.actions.back'))->outline()->variant('black')->render()?>
    </div>
</div>
<?php View::end(); ?>
