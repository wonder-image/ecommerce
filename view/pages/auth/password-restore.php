<?php
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.restore.title')]);
?>
<form id="password_restore" method="post" class="d-grid col-1 gap-4 mt-5" novalidate>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <?=FormField::key('token')->hidden()->value($token)?>
    <?=FormField::key('password')->password()->label((string) __t('ecommerce.auth.fields.password'))->required()?>
    <?=FormField::key('password_confirmation')->password()->label((string) __t('ecommerce.auth.fields.password_confirmation'))->required()?>
    <?=FormField::key('recaptcha')->recaptcha('ecommerce_password_restore')?>
    <button class="btn btn-primary wi-submit w-100" type="submit"><?=e(__t('ecommerce.auth.restore.submit'))?></button>
</form>
<?php View::end(); ?>
