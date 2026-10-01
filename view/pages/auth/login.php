<?php
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.login.title')]);
?>
<form id="login" method="post" class="d-grid col-1 gap-6 mt-6" novalidate>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)->render()?>
    <?=FormField::key('continue')->hidden()->value((string) ($_GET['continue'] ?? ''))->render()?>
    <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?>
    <div>
        <?=FormField::key('password')->password()->label((string) __t('ecommerce.auth.fields.password'))->required()?>
        <a class="p-r f-start text-small mt-2" href="<?=e(__r('ecommerce.auth.password.recovery'))?>"><?=e(__t('ecommerce.auth.login.forgot'))?></a>
    </div>
    <?=FormField::key('recaptcha')->recaptcha('ecommerce_login')?>
    <button class="btn btn-primary w-100 wi-input-submit wi-submit" type="submit"><?=e(__t('ecommerce.auth.login.submit'))?></button>
</form>

<?=View::component(Ecommerce::viewPath('components/auth/federated.php'), compact('csrf_token', 'oidc_nonce', 'google_client_id') + ['auth_surface' => 'login'])?>


<p class="text-small a-c mt-5"><a href="<?=e(__r('ecommerce.auth.signup.request'))?>"><?=e(__t('ecommerce.auth.login.signup'))?></a></p>
<?php View::end(); ?>
