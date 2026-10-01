<?php
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.signup.complete_title'), 'text' => (string) __t('ecommerce.auth.signup.step_two')]);
?>
<form id="sign_up_completion" method="post" class="d-grid col-4 col-p-1 gap-4 mt-5" novalidate>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <?=FormField::key('token')->hidden()->value($token)?>
    <div class="col-4 col-p-1"><?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->value($email)->disabled()?></div>
    <div class="col-1"><?=FormField::key('phone_prefix')->phonePrefix()->label((string) __t('ecommerce.auth.fields.prefix'))->required()->value($values['phone_prefix'] ?? '+39')?></div>
    <div class="col-3 col-p-1"><?=FormField::key('phone')->phone()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($values['phone'] ?? '')?></div>
    <div class="col-4 col-p-1"><?=FormField::key('password')->password()->label((string) __t($password_required ? 'ecommerce.auth.fields.password' : 'ecommerce.auth.fields.password_optional'))->required($password_required)?></div>
    <div class="col-4 col-p-1"><?=FormField::key('password_confirmation')->password()->label((string) __t('ecommerce.auth.fields.password_confirmation'))->required($password_required)?></div>
    <div class="col-4 col-p-1"><?=FormField::key('recaptcha')->recaptcha('ecommerce_signup_completion')?></div>
    <button class="btn btn-primary wi-input-submit wi-submit w-100 col-4 col-p-1" type="submit"><?=e(__t('ecommerce.auth.signup.complete_submit'))?></button>
</form>
<?php View::end(); ?>
