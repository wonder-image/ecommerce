<?php

use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCheckout;
use Wonder\Plugin\Ecommerce\Frontend\Slots;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;
use Wonder\Http\Csrf;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
// I servizi scelti stanno in sessione, accanto ai prodotti (CheckoutSubscriptions::cart).
$services = is_array($services ?? null) ? $services : null;
$serviceItems = array_values((array) ($services['items'] ?? []));
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$labels = [];
foreach (['coupon_remove', 'updating', 'summary_error', 'fees_total', 'today_total', 'subscriptions_first_charge'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}

Ecommerce::layout('shop');
// Dati di pagina ed eventi rimasti dopo un redirect (un servizio tolto da qui).
View::head(DataLayer::script([
    'type' => 'cart',
    'language' => __l(),
    'currency' => $currency,
], is_array($events ?? null) && $events !== [] ? $events : null));
?>
<?php
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('cart'));
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreStyle::sheet());
// La quantità che −/+ mandano, col punto: il carrello la rilegge così.
$step = static fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
// Dati degli slot `cart.*` (docs/slots.md).
$slot = ['order' => $order, 'items' => $items, 'currency' => $currency];
?>
<?=Slots::render('cart.before', $slot)?>
<?php if ($items === [] && $serviceItems === []): ?>
    <h1 class="title mb-6"><?=e(__t('ecommerce.cart.title'))?></h1>
    <?php // Lo stato vuoto della lib, come ordini e coupon dell'area cliente. ?>
    <section class="w-100 wi-box p-6">
        <div class="w-100 wi-empty-state" role="status">
            <i class="wi-empty-state__icon bi bi-bag" aria-hidden="true"></i>
            <h2 class="subtitle"><?=e(__t('ecommerce.cart.empty_title'))?></h2>
            <p class="text tx-secondary"><?=e(__t('ecommerce.cart.empty_text'))?></p>
            <?=Button::to((string) (__r('ecommerce.catalog.index') ?: '/'), (string) __t('ecommerce.cart.empty_cta'))->variant('primary')->arrow()?>
        </div>
    </section>
<?php else: ?>
    <div class="w-100 d-grid col-3 col-t-1 gap-6" data-checkout data-checkout-cart
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>">
        <div class="w-100 col-2 col-t-1 wi-box h-auto p-5 d-flex d-column gap-4">
            <?php // Sotto il titolo, in grigio: «Hai 5 articoli nel carrello». ?>
            <div class="w-100 mb-2">
                <h1 class="subtitle"><?=e(__t('ecommerce.cart.title'))?></h1>
                <p class="wi-cart__meta wi-cart__count"><?=e(__t('ecommerce.cart.in_cart', ['items' => (string) __t(($count = CartSession::withServices(CartPresenter::count($items))) === '1' ? 'ecommerce.cart.count_one' : 'ecommerce.cart.count', ['count' => $count])]))?></p>
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
                    <?=View::component(Ecommerce::viewPath('components/cart/thumb.php'), ['image' => $item['image'] ?? '', 'url' => $url])?>
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
                                <?=Csrf::field()?>
                                <?=$minus?>
                                <span class="wi-cart__quantity" aria-label="<?=e(__t('ecommerce.cart.quantity'))?>"><?=e(CartPresenter::quantity($quantity))?></span>
                                <?=Button::make('+')->type('submit')->size('sm')->variant('black')->outline()->attr('name', 'quantity')->attr('value', $step($quantity + 1))->attr('aria-label', (string) __t('ecommerce.cart.increase'))?>
                            </form>
                            <form id="cart_remove_<?=e((string) $id)?>" method="post" action="<?=e($remove)?>" data-cart-action>
                                <?=Csrf::field()?>
                                <button class="wi-cart__remove text-small" type="submit" data-wi-confirm="<?=e($confirm[0])?>" data-wi-confirm-title="<?=e($confirm[1])?>" data-wi-confirm-ok="<?=e($confirm[2])?>"><i class="bi bi-trash3"></i> <?=e(__t('ecommerce.cart.remove'))?></button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php foreach ($serviceItems as $position => $service): ?>
                <?php
                $name = (string) ($service['name'] ?? '');
                $children = (int) ($service['children'] ?? 0);
                $confirm = [
                    (string) ($children > 0
                        ? __t('ecommerce.cart.service_remove_confirm_children', ['name' => $name, 'count' => (string) $children])
                        : __t('ecommerce.cart.remove_confirm_text', ['name' => $name])),
                    (string) __t('ecommerce.cart.service_remove_confirm_title'),
                    (string) __t('ecommerce.cart.remove_confirm_ok'),
                ];
                ?>
                <?php if ($items !== [] || $position > 0): ?><hr class="wi-cart__line"><?php endif; ?>
                <?php // Un servizio si compra una volta: niente quantità, solo «Rimuovi». ?>
                <article class="w-100 wi-cart__item">
                    <?php $serviceUrl = (string) ($service['url'] ?? ''); ?>
                    <?=View::component(Ecommerce::viewPath('components/cart/thumb.php'), ['image' => $service['image'] ?? '', 'url' => $serviceUrl])?>
                    <div class="w-100 wi-cart__body">
                        <div class="w-100 wi-cart__head">
                            <div class="w-100">
                                <h2 class="text fw-600"><?php if ($serviceUrl !== ''): ?><a class="tx-black tx-none" href="<?=e($serviceUrl)?>"><?=e($name)?></a><?php else: ?><?=e($name)?><?php endif; ?></h2>
                                <?php if (($parts = (array) ($service['parts'] ?? [])) !== []): ?>
                                    <ul class="wi-cart__parts wi-cart__meta">
                                        <?php foreach ($parts as $part): ?><li><?=e($part)?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <p class="wi-cart__price"><strong class="d-block"><?=e((string) ($service['line_total_display'] ?? ''))?></strong></p>
                        </div>
                        <div class="w-100 wi-cart__foot" style="justify-content:flex-end">
                            <form id="cart_service_remove_<?=e((string) (int) $service['index'])?>" method="post" action="<?=e(__r('ecommerce.cart.service_remove', ['index' => (int) $service['index']]))?>" data-cart-action>
                                <?=Csrf::field()?>
                                <button class="wi-cart__remove text-small" type="submit" data-wi-confirm="<?=e($confirm[0])?>" data-wi-confirm-title="<?=e($confirm[1])?>" data-wi-confirm-ok="<?=e($confirm[2])?>"><i class="bi bi-trash3"></i> <?=e(__t('ecommerce.cart.remove'))?></button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?=Slots::render('cart.after_items', $slot)?>
        </div>
        <div class="w-100 d-flex d-column gap-5">
            <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), [
                'step' => 'cart', 'items' => $items, 'order' => $order, 'currency' => $currency,
                'coupons' => false, 'couponCodes' => [], 'notices' => [], 'services' => $services,
                // I bottoni rapidi stanno sopra «Procedi».
                'express' => Ecommerce::config('cart.express', true) ? ExpressCheckout::render('cart', $order) : '',
                'button' => (string) Button::to((string) __r('ecommerce.checkout.index'), (string) __t('ecommerce.cart.proceed'))->variant('primary')->class('w-100 a-c mt-5'),
            ])?>
            <?php if (ExpressCheckout::available() && ($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
                View::head('<script src="'.e($checkoutJs).'" defer></script>');
            } ?>
            <?php if ($coupons): ?>
                <?php
                $couponCodes = CheckoutSummary::couponCodes((int) ($order['id'] ?? 0));
                // Il tema Wonder non rende `Text`: all'accordion basta un oggetto con render().
                $coupon = new class ((string) View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['codes' => $couponCodes, 'return' => 'cart', 'id' => 'checkout-coupon', 'spacing' => '', 'order' => $order, 'currency' => $currency])) {
                    public function __construct(private string $html) {}
                    public function render(): string { return $this->html; }
                };
                ?>
                <?=Accordion::make((string) __t('ecommerce.checkout.coupon_title'))->icon('chevron')->expanded($couponCodes !== [])->class('wi-box h-auto p-5')->components([
                    $coupon,
                ])?>
            <?php endif; ?>
            <?=Slots::render('cart.after_summary', $slot)?>
        </div>
    </div>
    <?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
        View::head('<script src="'.e($checkoutJs).'" defer></script>');
    } ?>
<?php endif; ?>
<?=Slots::render('cart.after', $slot)?>
<?php View::end(); ?>
