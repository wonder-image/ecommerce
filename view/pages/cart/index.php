<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCheckout;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$labels = [];
foreach (['coupon_remove', 'updating', 'summary_error', 'fees_total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}

Ecommerce::layout('shop');
?>
<?php
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('cart'));
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreStyle::sheet());
// La quantità che −/+ mandano, col punto: il carrello la rilegge così.
$step = static fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
?>
<?php if ($items === []): ?>
    <h1 class="title mb-6"><?=e(__t('ecommerce.cart.title'))?></h1>
    <div class="w-100 wi-box p-6 a-c">
        <h2 class="subtitle"><?=e(__t('ecommerce.cart.empty_title'))?></h2>
        <p class="text mt-3"><?=e(__t('ecommerce.cart.empty_text'))?></p>
        <?=Button::to((string) (__r('ecommerce.catalog.index') ?: '/'), (string) __t('ecommerce.cart.back_to_shop'))->variant('primary')->class('mt-5')?>
    </div>
<?php else: ?>
    <div class="w-100 d-grid col-3 col-t-1 gap-6" data-checkout data-checkout-cart
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>">
        <div class="w-100 col-2 col-t-1 wi-box h-auto p-5 d-flex d-column gap-4">
            <?php // Sotto il titolo, in grigio: «Hai 5 articoli nel carrello». ?>
            <div class="w-100 mb-2">
                <h1 class="subtitle"><?=e(__t('ecommerce.cart.title'))?></h1>
                <p class="wi-cart__meta wi-cart__count"><?=e(__t('ecommerce.cart.in_cart', ['items' => (string) __t(($count = CartPresenter::count($items)) === '1' ? 'ecommerce.cart.count_one' : 'ecommerce.cart.count', ['count' => $count])]))?></p>
            </div>
            <?php foreach ($items as $index => $item): ?>
                <?php
                $id = (int) ($item['id'] ?? 0);
                $quantity = (float) ($item['quantity'] ?? 1);
                $remove = (string) __r('ecommerce.cart.remove', ['id' => $id]);
                $url = CartPresenter::url($item);
                $confirm = [
                    (string) __t('ecommerce.cart.remove_confirm_text', ['name' => (string) ($item['name'] ?? '')]),
                    (string) __t('ecommerce.cart.remove_confirm_title'),
                    (string) __t('ecommerce.cart.remove_confirm_ok'),
                ];
                $minus = Button::make('−')->type('submit')->size('sm')->variant('black')->outline()->attr('aria-label', (string) __t('ecommerce.cart.decrease'));
                // A 1 il − toglie il prodotto: stessa conferma di «Rimuovi», il csrf del form basta alla rimozione.
                $minus = $quantity <= 1
                    ? $minus->attr('formaction', $remove)->confirm(...$confirm)
                    : $minus->attr('name', 'quantity')->attr('value', $step($quantity - 1));
                ?>
                <?php if ($index > 0): ?><hr class="wi-cart__line"><?php endif; ?>
                <?php
                $title = (string) ($item['title'] ?? $item['name'] ?? '');
                $regular = CartPresenter::regularTotal($item);
                ?>
                <?php // In alto nome, scelte e prezzo; in basso quantità e «Rimuovi». La foto resta in cima, non si allunga con la riga. ?>
                <article class="w-100 wi-cart__item">
                    <<?=$url !== '' ? 'a href="'.e($url).'" tabindex="-1" aria-hidden="true"' : 'span'?> class="wi-thumb" style="--wi-thumb-size: 88px">
                        <?php if (trim((string) ($item['image'] ?? '')) !== ''): ?><img src="<?=e($item['image'])?>" alt="" loading="lazy"><?php endif; ?>
                    </<?=$url !== '' ? 'a' : 'span'?>>
                    <div class="w-100 wi-cart__body">
                        <div class="w-100 wi-cart__head">
                            <div class="w-100">
                                <h2 class="text fw-600"><?php if ($url !== ''): ?><a class="tx-black tx-none" href="<?=e($url)?>"><?=e($title)?></a><?php else: ?><?=e($title)?><?php endif; ?></h2>
                                <?php if (($variant = (string) ($item['variant'] ?? '')) !== ''): ?>
                                    <p class="wi-cart__meta"><?=e($variant)?></p>
                                <?php endif; ?>
                                <?php foreach (CartPresenter::details($item) as $detail): ?>
                                    <p class="wi-cart__meta"><?=e($detail)?></p>
                                <?php endforeach; ?>
                                <?php if (($parts = CartPresenter::parts($item)) !== []): ?>
                                    <ul class="wi-cart__parts wi-cart__meta">
                                        <?php foreach ($parts as $part): ?><li><?=e($part)?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <p class="wi-cart__price">
                                <strong class="d-block"><?=e(CartPresenter::money($item['line_total'] ?? 0, $currency))?></strong>
                                <?php if ($regular > 0): ?><s class="d-block wi-cart__meta"><?=e(CartPresenter::money($regular, $currency))?></s><?php endif; ?>
                                <?php if ($quantity !== 1.0): ?><span class="d-block wi-cart__meta"><?=e(__t('ecommerce.cart.each', ['price' => CartPresenter::money($item['unit_price'] ?? 0, $currency)]))?></span><?php endif; ?>
                            </p>
                        </div>
                        <div class="w-100 wi-cart__foot">
                            <form id="cart_quantity_<?=e((string) $id)?>" method="post" action="<?=e(__r('ecommerce.cart.quantity', ['id' => $id]))?>" class="wi-cart__stepper" data-cart-action>
                                <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
                                <?=$minus?>
                                <span class="wi-cart__quantity" aria-label="<?=e(__t('ecommerce.cart.quantity'))?>"><?=e(CartPresenter::quantity($quantity))?></span>
                                <?=Button::make('+')->type('submit')->size('sm')->variant('black')->outline()->attr('name', 'quantity')->attr('value', $step($quantity + 1))->attr('aria-label', (string) __t('ecommerce.cart.increase'))?>
                            </form>
                            <form id="cart_remove_<?=e((string) $id)?>" method="post" action="<?=e($remove)?>" data-cart-action>
                                <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
                                <button class="wi-cart__remove text-small" type="submit" data-wi-confirm="<?=e($confirm[0])?>" data-wi-confirm-title="<?=e($confirm[1])?>" data-wi-confirm-ok="<?=e($confirm[2])?>"><i class="bi bi-trash3"></i> <?=e(__t('ecommerce.cart.remove'))?></button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="w-100 d-flex d-column gap-5">
            <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), [
                'step' => 'cart', 'items' => $items, 'order' => $order, 'currency' => $currency, 'csrf_token' => $csrf_token,
                'coupons' => false, 'couponCode' => '', 'notices' => [],
                // I bottoni rapidi stanno sopra «Procedi».
                'express' => Ecommerce::config('cart.express', true) ? ExpressCheckout::render('cart', $order) : '',
                'button' => (string) Button::to((string) __r('ecommerce.checkout.index'), (string) __t('ecommerce.cart.proceed'))->variant('primary')->class('w-100 a-c mt-5'),
            ])?>
            <?php if (ExpressCheckout::available() && ($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
                View::head('<script src="'.e($checkoutJs).'" defer></script>');
            } ?>
            <?php if ($coupons): ?>
                <?php
                $couponCode = (string) ($order['coupon_code'] ?? '');
                // Il tema Wonder non rende `Text`: all'accordion basta un oggetto con render().
                $coupon = new class ((string) View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['csrf_token' => $csrf_token, 'code' => $couponCode, 'return' => 'cart', 'id' => 'checkout-coupon', 'spacing' => ''])) {
                    public function __construct(private string $html) {}
                    public function render(): string { return $this->html; }
                };
                ?>
                <?=Accordion::make((string) __t('ecommerce.checkout.coupon_title'))->icon('chevron')->expanded($couponCode !== '')->class('wi-box h-auto p-5')->components([
                    $coupon,
                ])?>
            <?php endif; ?>
        </div>
    </div>
    <?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
        View::head('<script src="'.e($checkoutJs).'" defer></script>');
    } ?>
<?php endif; ?>
<?php View::end(); ?>
