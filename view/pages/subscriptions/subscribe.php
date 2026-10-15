<?php

/**
 * Il passo prima di Stripe: riepilogo del prezzo, dati di fatturazione e
 * codice sconto. Pagina privata (`NOINDEX,NOFOLLOW`, niente dati strutturati,
 * `$SEO->breadcrumb = []`); il percorso visibile resta. dataLayer:
 * `begin_checkout` alla vista.
 *
 * @var array<string, mixed> $plan
 * @var array<string, mixed> $price
 * @var array<string, string> $values
 * @var array<string, mixed> $billing_fields
 * @var bool $include_tax
 * @var string $currency
 * @var string $plans_url
 * @var string $action
 * @var string $csrf_token
 */

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Breadcrumb;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutFields;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionTracking;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

View::head(DataLayer::script([
    'type' => 'subscription_checkout',
    'language' => __l(),
    'currency' => $currency,
], SubscriptionTracking::beginCheckout($price, (string) $plan['name'], $currency), (int) ($_SESSION['user_id'] ?? 0)));

Ecommerce::layout('checkout');
?>

<div class="w-100 d-grid col-1 gap-6">
    <?=Breadcrumb::make([
        ['url' => $plans_url, 'name' => (string) __t('ecommerce.subscriptions.plans.title')],
        ['url' => '', 'name' => (string) $plan['name']],
    ])?>

    <div>
        <h1 class="title"><?=e(__t('ecommerce.subscriptions.subscribe.title', ['plan' => $plan['name']]))?></h1>
    </div>

    <section class="wi-box p-6 d-flex d-column gap-2" aria-label="<?=e(__t('ecommerce.subscriptions.subscribe.summary'))?>">
        <h2 class="subtitle"><?=e($price['full_name'])?></h2>
        <p class="text">
            <?php if ($price['regular'] !== null): ?>
                <del class="tx-secondary"><?=e(CartPresenter::money($price['regular'], $currency))?></del>
            <?php endif; ?>
            <strong><?=e(CartPresenter::money($price['amount'], $currency))?></strong>
            <?=e(SubscriptionView::every($price['unit'], $price['count']))?>
            · <?=e(__t($include_tax ? 'ecommerce.subscriptions.tax_included' : 'ecommerce.subscriptions.tax_excluded'))?>
        </p>
        <?php if ($price['trial_days'] > 0): ?>
            <p class="text-small"><?=e(__t('ecommerce.subscriptions.trial_once', ['days' => $price['trial_days']]))?></p>
        <?php endif; ?>
        <?php if ($price['commitment_months'] > 0): ?>
            <p class="text-small"><?=e(__t('ecommerce.subscriptions.commitment', ['months' => $price['commitment_months']]))?></p>
        <?php endif; ?>
    </section>

    <form id="subscription_checkout" class="w-100 d-flex d-column gap-6" method="post" action="<?=e($action)?>" novalidate>
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <div class="w-100">
            <h2 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <div class="w-100 d-grid col-4 col-p-5 gap-4 gap-p-3">
                <?php foreach ($billing_fields as $key => $field): ?><div class="w-100 <?=CheckoutFields::cols($key)?>"><?=$field?></div><?php endforeach; ?>
            </div>
        </div>
        <div class="w-100">
            <?=FormField::key('coupon')->text()->label((string) __t('ecommerce.subscriptions.subscribe.coupon'))->value($values['coupon'] ?? '')?>
        </div>
        <p class="text-small tx-secondary"><?=e(__t('ecommerce.subscriptions.subscribe.stripe'))?></p>
        <?=Button::make((string) __t('ecommerce.subscriptions.subscribe.submit'))->type('submit')->variant('black')->size('lg')->class('w-100 wi-input-submit wi-submit')?>
    </form>
</div>

<?php View::end(); ?>
