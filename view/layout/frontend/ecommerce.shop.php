<?php

use Wonder\Elements\Components\Alert;
use Wonder\View\View;

$errors = array_values(array_filter(array_map('strval', (array) ($errors ?? []))));
$notice = trim((string) ($notice ?? ''));

View::layout('frontend.main');
?>
<main>
    <?php if ($notice !== ''): ?>
        <?=Alert::make($notice, 'success')->title((string) __t('ecommerce.cart.notice_title'))->render()?>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <?=Alert::make(implode("\n", $errors), 'error')->title((string) __t('ecommerce.cart.error_title'))->render()?>
    <?php endif; ?>
    <section class="intro">
        <div class="content">
            <div class="w-90 w-t-100"><?=$PAGE_CONTENT?></div>
        </div>
    </section>
</main>
<?php View::end(); ?>
