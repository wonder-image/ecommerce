<?php
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.email.sent_title'), 'text' => (string) __t('ecommerce.auth.email.sent_text')]);
?>
<p class="mt-5"><a class="btn btn-primary w-100" href="<?=e(__r('ecommerce.auth.login'))?>"><?=e(__t('ecommerce.auth.back_login'))?></a></p>
<?php View::end(); ?>
