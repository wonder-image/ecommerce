<?php

/**
 * Il riepilogo prima di Stripe: i servizi scelti (togliere una madre toglie
 * anche le sue righe), il totale del primo addebito col coupon, i dati di
 * fatturazione e il codice sconto. Pagina privata (`NOINDEX,NOFOLLOW`, niente
 * dati strutturati, `$SEO->breadcrumb = []`); il percorso visibile resta.
 * dataLayer: gli eventi rimasti dal POST (`add_to_cart`, `remove_from_cart`),
 * poi `begin_checkout` con un item per servizio.
 *
 * @var list<array<string, mixed>> $rows
 * @var array<string, mixed>|null $amounts
 * @var bool $include_tax
 * @var string $coupon_error
 * @var string $blocked
 * @var array<string, string> $values
 * @var array<string, mixed> $billing_fields
 * @var list<array<string, mixed>> $events
 * @var string $currency
 * @var string $plans_url
 * @var string $action
 * @var string $remove_url
 */

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Breadcrumb;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutFields;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;
use Wonder\Http\Csrf;

View::head(DataLayer::script([
    'type' => 'subscription_checkout',
    'language' => __l(),
    'currency' => $currency,
], $events !== [] ? $events : null, (int) ($_SESSION['user_id'] ?? 0)));

Ecommerce::layout('checkout');
?>

<div class="w-100 d-grid col-1 gap-6">
    <?=Breadcrumb::make([
        ['url' => $plans_url, 'name' => (string) __t('ecommerce.subscriptions.plans.title')],
        ['url' => '', 'name' => (string) __t('ecommerce.subscriptions.summary.title')],
    ])?>

    <div class="d-flex d-column gap-2">
        <h1 class="title"><?=e(__t('ecommerce.subscriptions.summary.title'))?></h1>
        <p class="text-small"><a class="tx-underline" href="<?=e($plans_url)?>"><?=e(__t('ecommerce.subscriptions.summary.add_more'))?></a></p>
    </div>

    <section class="wi-box p-6 d-flex d-column gap-4" aria-label="<?=e(__t('ecommerce.subscriptions.summary.services'))?>">
        <h2 class="subtitle"><?=e(__t('ecommerce.subscriptions.summary.services'))?></h2>
        <?php foreach ($rows as $row): ?>
            <div class="w-100 d-flex d-column gap-1">
                <div class="w-100 d-flex gap-4">
                    <p class="text"><strong><?=e($row['name'])?></strong> × <?=e((string) $row['quantity'])?></p>
                    <?php if ($row['line_total'] !== null): ?>
                        <p class="text ml-auto a-r"><?=e(CartPresenter::money($row['line_total'], $currency))?></p>
                    <?php endif; ?>
                </div>
                <?php if ($row['unit_price'] !== null && $row['price'] !== null): ?>
                    <p class="text-small tx-secondary">
                        <?=e(__t('ecommerce.subscriptions.summary.unit', ['price' => CartPresenter::money($row['unit_price'], $currency)]))?>
                        <?=e(SubscriptionView::every($row['price']['unit'], $row['price']['count']))?>
                    </p>
                <?php endif; ?>
                <?php foreach ($row['customization'] as $line): ?>
                    <p class="text-small"><?=e($line)?></p>
                <?php endforeach; ?>
                <?php if ($row['parent'] !== ''): ?>
                    <p class="text-small tx-secondary"><?=e(__t('ecommerce.subscriptions.summary.parent', ['name' => $row['parent']]))?></p>
                <?php endif; ?>
                <?php if ($row['price'] !== null && $row['price']['trial_days'] > 0): ?>
                    <p class="text-small"><?=e(__t('ecommerce.subscriptions.trial_once', ['days' => $row['price']['trial_days']]))?></p>
                <?php endif; ?>
                <?php if ($row['price'] !== null && $row['price']['commitment_months'] > 0): ?>
                    <p class="text-small"><?=e(__t('ecommerce.subscriptions.commitment', ['months' => $row['price']['commitment_months']]))?></p>
                <?php endif; ?>
                <div>
                    <?=Button::post($remove_url, (string) __t('ecommerce.subscriptions.summary.remove'))
                        ->hidden([Csrf::FIELD => Csrf::token(), 'index' => (string) $row['index']])
                        ->variant('danger')
                        ->outline()
                        ->size('sm')
                        ->confirm((string) __t($row['children'] > 0 ? 'ecommerce.subscriptions.summary.remove_with_children' : 'ecommerce.subscriptions.summary.remove_confirm', ['count' => (string) $row['children']]))?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($blocked !== ''): ?>
        <section class="wi-box p-6" role="alert">
            <p class="text"><?=e($blocked)?></p>
        </section>
    <?php elseif ($amounts !== null): ?>
        <section class="wi-box p-6 d-flex d-column gap-2" aria-label="<?=e(__t('ecommerce.subscriptions.summary.first_charge'))?>">
            <h2 class="subtitle"><?=e(__t('ecommerce.subscriptions.summary.first_charge'))?></h2>
            <div class="w-100 d-flex gap-4"><p class="text"><?=e(__t('ecommerce.subscriptions.summary.subtotal'))?></p><p class="text ml-auto a-r"><?=e(CartPresenter::money((float) $amounts['subtotal'], $currency))?></p></div>
            <?php if ((float) $amounts['discount_total'] > 0): ?>
                <div class="w-100 d-flex gap-4"><p class="text"><?=e(__t('ecommerce.subscriptions.summary.discount'))?></p><p class="text ml-auto a-r">− <?=e(CartPresenter::money((float) $amounts['discount_total'], $currency))?></p></div>
            <?php endif; ?>
            <div class="w-100 d-flex gap-4"><p class="text-small tx-secondary"><?=e(__t($include_tax ? 'ecommerce.subscriptions.summary.tax_included' : 'ecommerce.subscriptions.summary.tax'))?></p><p class="text-small tx-secondary ml-auto a-r"><?=e(CartPresenter::money((float) $amounts['tax_total'], $currency))?></p></div>
            <div class="w-100 d-flex gap-4"><p class="text"><strong><?=e(__t('ecommerce.subscriptions.summary.total'))?></strong></p><p class="text ml-auto a-r"><strong><?=e(CartPresenter::money((float) $amounts['total'], $currency))?></strong></p></div>
        </section>
    <?php endif; ?>

    <form id="subscription_checkout" class="w-100 d-flex d-column gap-6" method="post" action="<?=e($action)?>" novalidate>
        <?=Csrf::field()?>
        <div class="w-100">
            <h2 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <div class="w-100 d-grid col-4 col-p-5 gap-4 gap-p-3">
                <?php foreach ($billing_fields as $key => $field): ?><div class="w-100 <?=CheckoutFields::cols($key)?>"><?=$field?></div><?php endforeach; ?>
            </div>
        </div>
        <div class="w-100 d-flex gap-3">
            <div class="w-100">
                <?php
                $coupon = FormField::key('coupon')->text()->label((string) __t('ecommerce.subscriptions.summary.coupon'))->value($values['coupon'] ?? '');
                echo $coupon_error !== '' ? $coupon->error($coupon_error) : $coupon;
                ?>
            </div>
            <div class="mt-auto">
                <?=Button::make((string) __t('ecommerce.subscriptions.summary.apply'))->type('submit')->variant('black')->outline()->attr('name', 'intent')->attr('value', 'apply')?>
            </div>
        </div>
        <p class="text-small tx-secondary"><?=e(__t('ecommerce.subscriptions.summary.stripe'))?></p>
        <?php
        $pay = Button::make((string) __t('ecommerce.subscriptions.summary.submit'))->type('submit')->variant('black')->size('lg')->class('w-100 wi-input-submit wi-submit')->attr('name', 'intent')->attr('value', 'pay');
        echo $blocked !== '' ? $pay->disabled() : $pay;
        ?>
    </form>
</div>

<?php View::end(); ?>
