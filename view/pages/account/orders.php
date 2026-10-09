<?php

use Wonder\Elements\Components\Button;
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
    <div class="wi-row-table" style="--wi-row-table-columns: minmax(0, 2fr) minmax(0, 1fr) auto">
        <div class="wi-row-table__head">
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.account.orders.order'))?></span>
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.cart.total'))?></span>
            <span class="wi-row-table__cell"></span>
        </div>
        <?php foreach ($orders as $order): ?>
            <div class="wi-row-table__row">
                <div class="wi-row-table__cell">
                    <div class="wi-row-table__title"><?=e((string) __t('ecommerce.account.orders.number', ['number' => $order['number']]))?></div>
                    <div class="wi-row-table__subtitle"><?=e($order['date'])?></div>
                </div>
                <div class="wi-row-table__cell"><strong class="subtitle"><?=e($order['total'])?></strong></div>
                <div class="wi-row-table__cell">
                    <?=Button::to($order['href'], (string) __t('ecommerce.account.orders.view'))->outline()->variant('black')->size('sm')->arrow()->render()?>
                </div>
            </div>
        <?php endforeach; ?>
        <?=View::component('frontend.account.pagination', ['pagination' => $pagination, 'base_url' => $base_url])?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
