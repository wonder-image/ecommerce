<?php
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.recovery.title'), 'text' => (string) __t($sent ? 'ecommerce.auth.recovery.sent' : 'ecommerce.auth.recovery.text')]);
?>
<?php if (!$sent): ?>
<form id="password_recovery" method="post" class="d-grid col-1 gap-4 mt-5" novalidate>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->render()?>
    <?=FormField::key('recaptcha')->recaptcha('ecommerce_password_recovery')?>
    <button class="btn btn-primary wi-input-submit wi-submit w-100" type="submit"><?=e(__t('ecommerce.auth.recovery.submit'))?></button>
</form>
<?php endif; ?>
<div class="text-small a-c mt-5"><a href="<?=e(__r('ecommerce.auth.login'))?>"><?=e(__t('ecommerce.auth.back_login'))?></a></div>
<?php View::end(); ?>
