<?php
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.signup.title'), 'text' => (string) __t('ecommerce.auth.signup.step_one')]);
?>
<form id="sign_up" method="post" class="d-grid col-2 col-p-1 gap-4 mt-5" novalidate>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <?=FormField::key('continue')->hidden()->value((string) ($_POST['continue'] ?? $_GET['continue'] ?? ''))?>
    <?=FormField::key('name')->text()->label((string) __t('ecommerce.auth.fields.name'))->required()->value($values['name'] ?? '')?>
    <?=FormField::key('surname')->text()->label((string) __t('ecommerce.auth.fields.surname'))->required()->value($values['surname'] ?? '')?>
    <div class="col-2 col-p-1"><?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?></div>
    <div class="col-2 col-p-1"><?=FormField::key('accept_privacy_policy')->acceptDocument('privacy_policy')->required()?></div>
    <div class="col-2 col-p-1"><?=FormField::key('accept_terms_conditions')->acceptDocument('terms_conditions')->required()?></div>
    <div class="col-2 col-p-1"><?=FormField::key('recaptcha')->recaptcha('ecommerce_signup_request')?></div>
    <button class="btn btn-primary btn-submit wi-input-submit wi-submit w-100 col-2 col-p-1" type="submit"><?=e(__t('ecommerce.auth.signup.submit'))?></button>
</form>
<?=View::component(Ecommerce::viewPath('components/auth/federated.php'), compact('csrf_token', 'oidc_nonce', 'google_client_id') + ['auth_surface' => 'signup'])?>
<?php $continue = (string) ($_POST['continue'] ?? $_GET['continue'] ?? ''); ?>
<div class="text-small a-c mt-5"><a href="<?=e(__r('ecommerce.auth.login').($continue !== '' ? '?continue='.rawurlencode($continue) : ''))?>"><?=e(__t('ecommerce.auth.signup.login'))?></a></div>
<?php View::end(); ?>
