<?php

use Wonder\Elements\Components\Button;
use Wonder\Http\Route;
use Wonder\View\View;

$title ??= '';
$active ??= 'orders';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<div class="d-grid col-1 gap-8">
    <p class="wi-row-table__subtitle"><?=e((string) __t('ecommerce.account.orders.of_date', ['date' => $order['date']]))?></p>

    <section class="d-grid col-1 gap-2">
        <h3 class="subtitle"><?=e((string) __t('ecommerce.account.orders.info'))?></h3>
        <div>
            <?php foreach ($order['info'] as $row): ?>
                <div class="wi-data-row">
                    <div class="wi-data-row__cols">
                        <div class="wi-data-row__col">
                            <div class="wi-data-row__label"><?=e($row['label'])?></div>
                            <div class="wi-data-row__value">
                                <?php if ($row['href'] !== ''): ?>
                                    <a href="<?=e($row['href'])?>" target="_blank" rel="noopener noreferrer"><?=e($row['value'])?></a>
                                <?php else: ?>
                                    <?=e($row['value'])?>
                                <?php endif; ?>
                                <?php foreach ($row['icons'] as $icon): ?>
                                    <img src="<?=e($icon['src'])?>" alt="<?=e($icon['alt'])?>" height="20">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="d-grid col-1 gap-2">
        <h3 class="subtitle"><?=e((string) __t('ecommerce.account.orders.products'))?></h3>
        <div class="wi-row-table" style="--wi-row-table-columns: minmax(0, 3fr) minmax(0, 1fr) minmax(0, 1fr)">
            <?php foreach ($order['items'] as $item): ?>
                <div class="wi-row-table__row">
                    <div class="wi-row-table__cell d-flex gap-4">
                        <span class="wi-thumb">
                            <?php if ($item['image'] !== ''): ?><img src="<?=e($item['image'])?>" alt="" loading="lazy"><?php endif; ?>
                        </span>
                        <span class="w-100">
                            <span class="wi-row-table__title d-block"><?=e($item['name'])?></span>
                            <?php foreach ($item['details'] as $detail): ?>
                                <span class="wi-row-table__subtitle d-block"><?=e($detail)?></span>
                            <?php endforeach; ?>
                            <?php foreach ($item['children'] as $child): ?>
                                <span class="wi-row-table__subtitle d-block"><?=e(
                                    ($child['choice'] ? (string) __t('ecommerce.account.orders.choice').': ' : '')
                                    .$child['name']
                                    .($child['quantity'] !== '' ? ' × '.$child['quantity'] : '')
                                    .($child['details'] !== [] ? ' · '.implode(' · ', $child['details']) : '')
                                )?></span>
                            <?php endforeach; ?>
                        </span>
                    </div>
                    <div class="wi-row-table__cell"><?=e($item['quantity'])?></div>
                    <div class="wi-row-table__cell"><?=e($item['total'])?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="d-grid col-1 gap-2">
        <h3 class="subtitle"><?=e((string) __t('ecommerce.account.orders.summary'))?></h3>
        <?php foreach ($order['summary'] as $row): ?>
            <div class="d-flex justify-content-between">
                <span><?=e($row['label'])?></span>
                <span><?=e($row['value'])?></span>
            </div>
        <?php endforeach; ?>
        <div class="d-flex justify-content-between">
            <span><?=e((string) __t('ecommerce.cart.total'))?></span>
            <strong class="subtitle"><?=e($order['total'])?></strong>
        </div>
    </section>

    <div class="wi-address-grid">
        <?php if ($order['delivery'] !== null): ?>
            <div class="wi-address-card">
                <div class="wi-address-card__body">
                    <div class="wi-row-table__title"><?=e($order['delivery']['title'])?></div>
                    <div><?=$order['delivery']['html']?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="wi-address-card">
            <div class="wi-address-card__body">
                <div class="wi-row-table__title"><?=e((string) __t('ecommerce.checkout.billing'))?></div>
                <div><?=$order['billing']?></div>
            </div>
        </div>
    </div>

    <div>
        <?=Button::to(Route::url('account.orders'), (string) __t('ecommerce.account.orders.back'))->outline()->variant('black')->render()?>
    </div>
</div>
<?php View::end(); ?>
