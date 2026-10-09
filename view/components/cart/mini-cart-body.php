<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$order = (array) (($cart ?? [])['order'] ?? []);
$items = CartPresenter::lines(array_values((array) (($cart ?? [])['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
?>
<div class="w-100 h-100">
    <?php if (trim((string) ($notice ?? '')) !== ''): ?>
        <div class="alert alert-success" role="status"><?=e($notice)?></div>
    <?php endif; ?>
    <?php if ((array) ($errors ?? []) !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ((array) $errors as $error): ?>
                <p><?=e($error)?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($items === []): ?>
        <div class="center w-100 a-c">
            <div class="title"><i class="bi bi-cart3" aria-hidden="true"></i></div>
            <p class="subtitle mt-4"><?=e(__t('ecommerce.cart.mini.empty'))?></p>
        </div>
    <?php else: ?>
        <div class="w-100 d-grid col-1 gap-4" aria-live="polite">
            <?php foreach ($items as $item): ?>
                <?php $itemId = (int) ($item['id'] ?? 0); ?>
                <article class="w-100 d-grid col-4 gap-3">
                    <div class="f-1-1 bg-light o-hidden">
                        <?php if (trim((string) ($item['image'] ?? '')) !== ''): ?>
                            <img src="<?=e($item['image'])?>" alt="" class="w-100 h-100 bg bg-cover" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="col-2">
                        <h3 class="text fw-600"><?=e($item['name'] ?? '')?></h3>
                        <p class="text-small mt-2"><?=e(CartPresenter::quantity($item['quantity'] ?? 1))?> × <?=e(CartPresenter::money($item['unit_price'] ?? $item['price'] ?? 0, $currency))?></p>
                        <strong class="text-small mt-2"><?=e(CartPresenter::money($item['line_total'] ?? 0, $currency))?></strong>
                    </div>
                    <form method="post" action="<?=e(__r('ecommerce.cart.remove', ['id' => $itemId]))?>" data-ecommerce-mini-cart-form>
                        <?=FormField::key('csrf_token')->hidden()->value((string) $csrf_token)?>
                        <button class="btn btn-sm" type="submit" aria-label="<?=e(__t('ecommerce.cart.mini.remove_item', ['name' => $item['name'] ?? '']))?>">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="w-100 d-grid col-2 gap-3 mt-5">
            <span><?=e(__t('ecommerce.cart.total'))?></span>
            <strong class="a-r"><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
        </div>
    <?php endif; ?>
</div>
