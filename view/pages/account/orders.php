<?php

use Wonder\View\View;

$title ??= '';
$active ??= 'orders';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<?php if ($orders === []): ?>
    <div class="wi-empty-state">
        <i class="wi-empty-state__icon bi bi-bag" aria-hidden="true"></i>
        <p><?=e((string) __t('ecommerce.account.orders.empty'))?></p>
    </div>
<?php else: ?>
    <?php foreach ($orders as $row): ?>
        <?=View::component('frontend.account.row', $row)?>
    <?php endforeach; ?>
    <?=View::component('frontend.account.pagination', ['pagination' => $pagination, 'base_url' => $base_url])?>
<?php endif; ?>
<?php View::end(); ?>
