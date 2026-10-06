<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$labels = [];
foreach (['coupon_remove', 'updating', 'summary_error'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'cart'])?>
<h1 class="title mb-6"><?=e(__t('ecommerce.cart.title'))?></h1>
<?php if ($items === []): ?>
    <div class="wi-box p-6 a-c">
        <h2 class="subtitle"><?=e(__t('ecommerce.cart.empty_title'))?></h2>
        <p class="text mt-3"><?=e(__t('ecommerce.cart.empty_text'))?></p>
        <?=Button::to((string) (__r('ecommerce.catalog.index') ?: '/'), (string) __t('ecommerce.cart.back_to_shop'))->variant('primary')->class('mt-5')?>
    </div>
<?php else: ?>
    <div class="d-grid col-3 col-t-1 gap-6" data-checkout data-checkout-cart data-step="cart"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>">
        <div class="col-2 col-t-1 wi-box p-5 d-grid col-1 gap-5">
            <p class="text-small"><?=e(__t('ecommerce.cart.count', ['count' => CartPresenter::count($items)]))?></p>
            <?php foreach ($items as $item): ?>
                <article class="d-flex d-p-column gap-4">
                    <span class="wi-thumb" style="--wi-thumb-size: 88px">
                        <?php if (trim((string) ($item['image'] ?? '')) !== ''): ?><img src="<?=e($item['image'])?>" alt="" loading="lazy"><?php endif; ?>
                    </span>
                    <div class="w-100">
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
        <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), [
            'step' => 'cart', 'items' => $items, 'order' => $order, 'currency' => $currency, 'csrf_token' => $csrf_token,
            'coupons' => $coupons, 'couponCode' => (string) ($order['coupon_code'] ?? ''), 'notices' => [],
            'button' => (string) Button::to((string) __r('ecommerce.checkout.index'), (string) __t('ecommerce.cart.proceed'))->variant('primary')->class('w-100 mt-5'),
        ])?>
    </div>
    <?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
        <script src="<?=e($checkoutJs)?>"></script>
    <?php endif; ?>
<?php endif; ?>
<?php View::end(); ?>
