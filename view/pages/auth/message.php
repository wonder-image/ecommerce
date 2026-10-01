<?php
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;
Ecommerce::layout('auth', ['title' => (string) __t('ecommerce.auth.message.title'), 'text' => (string) __t($message_key)]);
?>
<p class="mt-5"><a class="btn btn-primary w-100" href="<?=e(__r('ecommerce.auth.login'))?>"><?=e(__t('ecommerce.auth.back_login'))?></a></p>
<?php View::end(); ?>
