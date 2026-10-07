<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<div class="w-100 wi-box p-6 a-c">
    <h1 class="title"><?=e(__t('ecommerce.checkout.completed.title'))?></h1>
    <p class="text mt-4"><?=e(__t('ecommerce.checkout.completed.text', ['number' => (string) ($result['order_number'] ?? '')]))?></p>
    <?php if (trim((string) ($result['instructions'] ?? '')) !== ''): ?>
        <p class="text mt-4"><?=nl2br(e((string) $result['instructions']))?></p>
    <?php endif; ?>
    <a class="btn btn-primary mt-6" href="<?=e(__r('ecommerce.account.index'))?>"><?=e(__t('ecommerce.checkout.completed.account'))?></a>
</div>
<?php View::end(); ?>
