<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');

Ecommerce::layout('shop', compact('errors', 'notice'));
?>
<div class="d-grid col-2 col-p-1 gap-4 mb-8">
    <div>
        <h1 class="title"><?=e(__t('ecommerce.cart.title'))?></h1>
        <?php if ($items !== []): ?>
            <p class="text-small mt-2"><?=e(__t('ecommerce.cart.count', ['count' => CartPresenter::count($items)]))?></p>
        <?php endif; ?>
    </div>
    <?php if ($items !== []): ?>
        <div class="a-r a-p-l"><?=Button::to((string) __r('ecommerce.checkout.index'), (string) __t('ecommerce.cart.checkout'))->variant('primary')?></div>
    <?php endif; ?>
</div>

<?php if ($items === []): ?>
    <div class="wi-box p-6 a-c">
        <h2 class="subtitle"><?=e(__t('ecommerce.cart.empty_title'))?></h2>
        <p class="text mt-3"><?=e(__t('ecommerce.cart.empty_text'))?></p>
    </div>
<?php else: ?>
    <div class="d-grid col-3 col-p-1 gap-6">
        <div class="col-2 col-p-1 d-grid col-1 gap-4">
            <?php foreach ($items as $item): ?>
                <article class="wi-box p-4 d-grid col-4 col-p-1 gap-4">
                    <div class="f-1-1 bg-light o-hidden">
                        <?php if (trim((string) ($item['image'] ?? '')) !== ''): ?>
                            <img src="<?=e($item['image'])?>" alt="<?=e($item['name'] ?? '')?>" class="w-100 h-100 bg bg-cover" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="col-3 col-p-1">
                        <div class="d-grid col-2 col-p-1 gap-3">
                            <div>
                                <h2 class="text fw-600"><?=e($item['name'] ?? '')?></h2>
                                <?php if (trim((string) ($item['sku'] ?? '')) !== ''): ?>
                                    <p class="text-small tx-secondary mt-1"><?=e(__t('ecommerce.cart.sku', ['sku' => $item['sku']]))?></p>
                                <?php endif; ?>
                            </div>
                            <p class="text fw-700 a-r a-p-l"><?=e(CartPresenter::money($item['line_total'] ?? 0, $currency))?></p>
                        </div>
                        <div class="d-flex d-p-column gap-3 mt-4">
                            <form id="cart_quantity_<?=e((string) ($item['id'] ?? 0))?>" method="post" action="<?=e(__r('ecommerce.cart.quantity', ['id' => (int) ($item['id'] ?? 0)]))?>" class="d-flex gap-2">
                                <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
                                <div class="w-25"><?=FormField::key('quantity')->number()->label((string) __t('ecommerce.cart.quantity'))->required()->value(CartPresenter::quantity($item['quantity'] ?? 1))?></div>
                                <?=Button::make((string) __t('ecommerce.cart.update'))->type('submit')->variant('primary')->size('sm')?>
                            </form>
                            <form id="cart_remove_<?=e((string) ($item['id'] ?? 0))?>" method="post" action="<?=e(__r('ecommerce.cart.remove', ['id' => (int) ($item['id'] ?? 0)]))?>">
                                <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
                                <button class="btn btn-sm" type="submit"><?=e(__t('ecommerce.cart.remove'))?></button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <aside class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.cart.summary'))?></h2>
            <div class="d-grid col-2 gap-3 mb-3">
                <span><?=e(__t('ecommerce.cart.products_total'))?></span>
                <strong class="a-r"><?=e(CartPresenter::money($order['products_total'] ?? 0, $currency))?></strong>
            </div>
            <?php if ((float) ($order['discount_total'] ?? 0) > 0): ?>
                <div class="d-grid col-2 gap-3 mb-3">
                    <span><?=e(__t('ecommerce.cart.discount'))?></span>
                    <strong class="a-r">-<?=e(CartPresenter::money($order['discount_total'], $currency))?></strong>
                </div>
            <?php endif; ?>
            <div class="d-grid col-2 gap-3 pt-4">
                <span class="fw-700"><?=e(__t('ecommerce.cart.total'))?></span>
                <strong class="a-r"><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
            </div>
            <?=Button::to((string) __r('ecommerce.checkout.index'), (string) __t('ecommerce.cart.checkout'))->variant('primary')->class('w-100 mt-5')?>
        </aside>
    </div>
<?php endif; ?>
<?php View::end(); ?>
