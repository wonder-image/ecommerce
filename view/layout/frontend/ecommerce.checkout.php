<?php

use Wonder\Elements\Components\Alert;
use Wonder\View\View;

$errors = array_values(array_filter(array_map('strval', (array) ($errors ?? []))));
$notice = trim((string) ($notice ?? ''));

View::layout('frontend.minimal');
echo \Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('checkout');
?>
    <?php if ($notice !== ''): ?>
        <?=Alert::make($notice, 'success')->title((string) __t('ecommerce.checkout.notice_title'))->render()?>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <?=Alert::make(implode("\n", $errors), 'error')->title((string) __t('ecommerce.checkout.error_title'))->render()?>
    <?php endif; ?>
    <section>
        <div class="content">
            <div class="w-100"><?=$PAGE_CONTENT?></div>
        </div>
    </section>
<?php View::end(); ?>
