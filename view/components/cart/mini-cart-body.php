<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

$order = (array) (($cart ?? [])['order'] ?? []);
$items = CartPresenter::lines(array_values((array) (($cart ?? [])['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
?>
<?php // La lib non ha utility per flex:1, space-between e i colori dei bordi: quei pochi valori stanno inline. ?>
<div class="w-100 h-100 d-flex d-column">
    <?php if ((array) ($errors ?? []) !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ((array) $errors as $error): ?>
                <p><?=e($error)?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($items === []): ?>
        <div class="center w-100 a-c">
            <div class="title mt-2"><i class="bi bi-cart3" aria-hidden="true"></i></div>
            <p class="subtitle mt-4"><?=e(__t('ecommerce.cart.mini.empty'))?></p>
            <div class="w-100 d-flex j-content-center mt-5">
                <button class="btn btn-dark-o" type="button" data-cart-close>
                    <i class="bi bi-arrow-left mr-2" aria-hidden="true"></i><?=e(__t('ecommerce.cart.mini.continue_shopping'))?>
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="w-100 no-scrollbar" style="flex:1 1 auto;min-height:0;overflow-y:auto" aria-live="polite">
            <?php foreach (array_values($items) as $index => $item): ?>
                <?php
                $itemId = (int) ($item['id'] ?? 0);
                $last = $index === count($items) - 1;
                $url = CartPresenter::url($item);
                ?>
                <article class="w-100 d-grid gap-4 box-border <?=$index === 0 ? 'pb-4' : 'pt-4 pb-4'?>" style="grid-template-columns:72px 1fr<?=$last ? '' : ';border-bottom:1px solid var(--black-color-10)'?>">
                    <?=View::component(Ecommerce::viewPath('components/cart/thumb.php'), ['image' => $item['image'] ?? '', 'url' => $url])?>
                    <div class="w-100 d-flex d-column gap-1">
                        <div class="w-100 d-grid gap-2" style="grid-template-columns:1fr auto;align-items:start">
                            <h3 class="text fw-600">
                                <?php if ($url !== ''): ?>
                                    <a class="tx-black tx-none" href="<?=e($url)?>"><?=e($item['name'] ?? '')?></a>
                                <?php else: ?>
                                    <?=e($item['name'] ?? '')?>
                                <?php endif; ?>
                            </h3>
                            <form method="post" action="<?=e(__r('ecommerce.cart.remove', ['id' => $itemId]))?>" data-ecommerce-mini-cart-form>
                                <?=FormField::key('csrf_token')->hidden()->value((string) $csrf_token)?>
                                <button class="tx-black" type="submit" style="width:28px;height:28px;border:0;border-radius:50%;background:transparent;cursor:pointer" aria-label="<?=e(__t('ecommerce.cart.mini.remove_item', ['name' => $item['name'] ?? '']))?>">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                        <?php if (($parts = CartPresenter::parts($item)) !== []): ?>
                            <ul class="w-100" style="list-style:none;padding:0;margin:0;font-size:12px;line-height:16px;color:var(--black-color-60)">
                                <?php foreach ($parts as $part): ?><li><?=e($part)?></li><?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="w-100 d-grid gap-2" style="grid-template-columns:1fr auto;align-items:baseline;margin-top:auto;font-size:14px">
                            <span style="font-size:13px;color:var(--black-color-60)"><?=e(CartPresenter::quantity($item['quantity'] ?? 1))?> × <?=e(CartPresenter::money($item['unit_price'] ?? $item['price'] ?? 0, $currency))?></span>
                            <strong><?=e(CartPresenter::money($item['line_total'] ?? 0, $currency))?></strong>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php // Il totale resta fermo in fondo, sopra i bottoni: scorrono solo gli articoli. ?>
        <div class="w-100 d-grid gap-2 pt-4 box-border" style="flex:none;grid-template-columns:1fr auto;align-items:baseline;border-top:1px solid var(--black-color);font-size:16px">
            <span><?=e(__t('ecommerce.cart.total'))?></span>
            <strong><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
        </div>
    <?php endif; ?>
</div>
