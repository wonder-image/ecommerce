<?php

use Wonder\Elements\Components\Button;
use Wonder\Http\Route;
use Wonder\View\View;

$title ??= '';
$active ??= 'orders';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<div class="d-grid col-1 gap-8 w-100">
    <p class="wi-row-table__subtitle"><?=e((string) __t('ecommerce.account.orders.of_date', ['date' => $order['date']]))?></p>

    <div class="w-100 d-grid col-1 gap-2">
        <h3 class="subtitle"><?=e((string) __t('ecommerce.account.orders.info'))?></h3>
        <div>
            <?php /* Tre informazioni per riga, come le righe dei dati dell'area cliente. */ ?>
            <?php foreach (array_chunk($order['info'], 3) as $group): ?>
                <div class="wi-data-row">
                    <div class="wi-data-row__cols">
                        <?php foreach ($group as $row): ?>
                            <div class="wi-data-row__col">
                                <div class="wi-data-row__label"><?=e($row['label'])?></div>
                                <div class="wi-data-row__value">
                                    <?php if ($row['href'] !== ''): ?>
                                        <a href="<?=e($row['href'])?>" target="_blank" rel="noopener noreferrer"><?=e($row['value'])?></a>
                                    <?php else: ?>
                                        <?=e($row['value'])?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="w-100 d-grid col-1 gap-2">
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
                    <div class="wi-row-table__cell">× <?=e($item['quantity'])?></div>
                    <div class="wi-row-table__cell"><?=e($item['total'])?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="w-100 d-grid col-1 gap-2">
        <h3 class="subtitle"><?=e((string) __t('ecommerce.account.orders.summary'))?></h3>
        <?php foreach ($order['summary'] as $row): ?>
            <div class="w-100 d-grid col-2 gap-3">
                <span><?=e($row['label'])?></span>
                <span class="a-r"><?=e($row['value'])?></span>
            </div>
        <?php endforeach; ?>
        <div class="w-100 d-grid col-2 gap-3">
            <span><?=e((string) __t('ecommerce.cart.total'))?></span>
            <strong class="subtitle a-r"><?=e($order['total'])?></strong>
        </div>
    </div>

    <?php /* Gli indirizzi come le altre sezioni: titolo e testo, uno accanto all'altro, senza cornice. */ ?>
    <div class="w-100 d-grid col-2 col-p-1 gap-8">
        <?php if ($order['delivery'] !== null): ?>
            <div class="d-grid col-1 gap-2">
                <h3 class="subtitle"><?=e($order['delivery']['title'])?></h3>
                <div class="text"><?=$order['delivery']['html']?></div>
            </div>
        <?php endif; ?>
        <div class="d-grid col-1 gap-2">
            <h3 class="subtitle"><?=e((string) __t('ecommerce.checkout.billing'))?></h3>
            <div class="text"><?=$order['billing']?></div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-3">
        <?=Button::to(Route::url('account.orders'), (string) __t('ecommerce.account.orders.back'))->outline()->variant('black')->render()?>
        <?php if ($order['proforma_url'] !== ''): ?>
            <?=Button::to($order['proforma_url'], (string) __t('ecommerce.account.orders.proforma'))->variant('black')->icon('bi bi-file-earmark-pdf')->target('_blank')->attr('id', 'order_proforma_download')->render()?>
        <?php endif; ?>
    </div>
</div>
<?php View::end(); ?>
