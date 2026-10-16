<?php

/**
 * Un servizio prima di entrare nella selezione: quantità, personalizzazioni
 * del piano e, se il piano ne vuole una, la riga madre. Pagina privata
 * (`NOINDEX,NOFOLLOW`, niente dati strutturati, `$SEO->breadcrumb = []`); il
 * percorso visibile resta. dataLayer: nessun evento alla vista, `add_to_cart`
 * parte dalla pagina dopo il POST.
 *
 * @var array<string, mixed> $plan
 * @var array<string, mixed> $price
 * @var string $title
 * @var array{quantity: mixed, customizations: list<array{field: mixed, help: string}>, parent: mixed} $fields
 * @var bool $needs_parent
 * @var list<string> $missing_parents
 * @var int $selected
 * @var bool $include_tax
 * @var string $currency
 * @var string $plans_url
 * @var string $summary_url
 * @var string $action
 * @var string $csrf_token
 */

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Breadcrumb;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

View::head(DataLayer::script([
    'type' => 'subscription_configure',
    'language' => __l(),
    'currency' => $currency,
], null, (int) ($_SESSION['user_id'] ?? 0)));

Ecommerce::layout('checkout');

$blocked = $needs_parent && $fields['parent'] === null;
?>

<div class="w-100 d-grid col-1 gap-6">
    <?=Breadcrumb::make([
        ['url' => $plans_url, 'name' => (string) __t('ecommerce.subscriptions.plans.title')],
        ['url' => '', 'name' => (string) $plan['name']],
    ])?>

    <div class="d-flex d-column gap-2">
        <h1 class="title"><?=e($title)?></h1>
        <?php if ($selected > 0): ?>
            <p class="text-small"><a class="tx-underline" href="<?=e($summary_url)?>"><?=e(__t('ecommerce.subscriptions.configure.to_summary', ['count' => (string) $selected]))?></a></p>
        <?php endif; ?>
    </div>

    <section class="wi-box p-6 d-flex d-column gap-2" aria-label="<?=e(__t('ecommerce.subscriptions.configure.price'))?>">
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

    <?php if ($blocked): ?>
        <section class="wi-box p-6 d-flex d-column gap-2" aria-label="<?=e(__t('ecommerce.subscriptions.configure.parent'))?>">
            <p class="text"><?=e(__t('ecommerce.subscriptions.configure.parent_missing', ['plans' => implode(', ', $missing_parents)]))?></p>
            <p class="text-small"><a class="tx-underline" href="<?=e($plans_url)?>"><?=e(__t('ecommerce.subscriptions.configure.back_to_plans'))?></a></p>
        </section>
    <?php endif; ?>

    <form id="subscription_configure" class="w-100 d-flex d-column gap-4" method="post" action="<?=e($action)?>" novalidate>
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <div class="w-100"><?=$fields['quantity']?></div>
        <?php foreach ($fields['customizations'] as $customization): ?>
            <div class="w-100 d-flex d-column gap-1">
                <?=$customization['field']?>
                <?php if ($customization['help'] !== ''): ?>
                    <p class="text-small tx-secondary"><?=e($customization['help'])?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($fields['parent'] !== null): ?>
            <div class="w-100 d-flex d-column gap-1">
                <?=$fields['parent']?>
                <p class="text-small tx-secondary"><?=e(__t('ecommerce.subscriptions.configure.parent_help'))?></p>
            </div>
        <?php endif; ?>
        <?php
        $submit = Button::make((string) __t('ecommerce.subscriptions.add'))->type('submit')->variant('black')->size('lg')->class('w-100 wi-input-submit wi-submit');
        echo $blocked ? $submit->disabled() : $submit;
        ?>
    </form>
</div>

<?php View::end(); ?>
