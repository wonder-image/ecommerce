<?php

use Wonder\View\View;

$title ??= '';
$active ??= 'coupons';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<?php if ($coupons === []): ?>
    <div class="wi-empty-state">
        <i class="wi-empty-state__icon bi bi-ticket-perforated" aria-hidden="true"></i>
        <p><?=e((string) __t('ecommerce.account.coupons.empty'))?></p>
    </div>
<?php else: ?>
    <div class="wi-row-table" style="--wi-row-table-columns: repeat(3, minmax(0, 1fr))">
        <div class="wi-row-table__head">
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.account.coupons.code'))?></span>
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.account.coupons.value'))?></span>
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.account.coupons.uses'))?></span>
        </div>
        <?php foreach ($coupons as $coupon): ?>
            <div class="wi-row-table__row">
                <div class="wi-row-table__cell">
                    <div class="wi-row-table__title"><?=e($coupon['code'])?></div>
                </div>
                <div class="wi-row-table__cell"><?=e($coupon['value'])?></div>
                <div class="wi-row-table__cell"><?=e($coupon['uses'])?></div>
            </div>
        <?php endforeach; ?>
        <?=View::component('frontend.account.pagination', ['pagination' => $pagination, 'base_url' => $base_url])?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
