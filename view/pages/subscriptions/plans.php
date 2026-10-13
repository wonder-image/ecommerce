<?php

/**
 * La pagina pubblica dei piani (G10 §5).
 *
 * SEO: `INDEX,FOLLOW`, un solo titolo h1, piani resi lato server. schema.org:
 * `ItemList` di `Product` con gli stessi importi in pagina. Breadcrumb:
 * Home › Abbonamenti, a video e in JSON-LD (dal core). dataLayer:
 * `view_item_list` alla vista, `select_item` al clic su un prezzo.
 *
 * @var list<array<string, mixed>> $plans
 * @var string $title
 * @var string $page_url
 * @var list<array{url: string, name: string}> $breadcrumbs
 * @var bool $include_tax
 * @var string $currency
 */

use Wonder\Elements\Components\Breadcrumb;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

$plans = is_array($plans ?? null) ? $plans : [];
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$listName = (string) $title;

$SEO->schemaOrg = PlanCatalog::schemaOrg($plans, (string) $page_url, $listName, (string) $currency);

View::head(DataLayer::script([
    'type' => 'subscription_plans',
    'language' => __l(),
    'currency' => $currency,
], [
    'event' => 'view_item_list',
    'ecommerce' => [
        'item_list_name' => $listName,
        'currency' => $currency,
        'items' => PlanCatalog::trackingItems($plans),
    ],
], (int) ($_SESSION['user_id'] ?? 0)));

Ecommerce::layout('shop');
?>

<article class="d-grid col-1 gap-6 w-100">

<?=Breadcrumb::make($breadcrumbs)?>

<div>
    <h1 class="title"><?=e($title)?></h1>
    <p class="text mt-2"><?=e(__t('ecommerce.subscriptions.plans.intro'))?></p>
</div>

<?php if ($plans === []): ?>
    <div class="wi-empty-state">
        <i class="wi-empty-state__icon bi bi-arrow-repeat" aria-hidden="true"></i>
        <p><?=e(__t('ecommerce.subscriptions.plans.empty'))?></p>
    </div>
<?php else: ?>
    <div class="d-grid col-3 col-t-2 col-p-1 gap-6 w-100" data-subscription-plans>
        <?php $index = 0; ?>
        <?php foreach ($plans as $plan): ?>
            <section class="wi-box p-6 d-flex d-column gap-4" aria-labelledby="piano-<?=e($plan['code'])?>">
                <h2 class="subtitle" id="piano-<?=e($plan['code'])?>"><?=e($plan['name'])?></h2>
                <?php if ($plan['description'] !== ''): ?>
                    <p class="text"><?=e($plan['description'])?></p>
                <?php endif; ?>
                <?php if ($plan['features'] !== []): ?>
                    <ul class="text-small d-flex d-column gap-1">
                        <?php foreach ($plan['features'] as $feature): ?>
                            <li><?=e(SubscriptionView::featureLabel($feature['key']))?>: <strong><?=e($feature['value'])?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <ul class="d-flex d-column gap-3">
                    <?php foreach ($plan['prices'] as $price): ?>
                        <?php $item = PlanCatalog::trackingItem($price, $plan['name'], $index++); ?>
                        <li class="d-flex d-column gap-1">
                            <span class="text-small tx-secondary"><?=e($price['name'])?></span>
                            <span class="subtitle">
                                <?php if ($price['regular'] !== null): ?>
                                    <del class="text-small tx-secondary"><?=e(CartPresenter::money($price['regular'], $currency))?></del>
                                <?php endif; ?>
                                <?=e(CartPresenter::money($price['amount'], $currency))?>
                                <span class="text-small"><?=e(SubscriptionView::every($price['unit'], $price['count']))?></span>
                            </span>
                            <span class="text-small tx-secondary"><?=e(__t($include_tax ? 'ecommerce.subscriptions.tax_included' : 'ecommerce.subscriptions.tax_excluded'))?></span>
                            <?php if ($price['trial_days'] > 0): ?>
                                <span class="text-small"><?=e(__t('ecommerce.subscriptions.trial', ['days' => $price['trial_days']]))?></span>
                            <?php endif; ?>
                            <?php if ($price['commitment_months'] > 0): ?>
                                <span class="text-small"><?=e(__t('ecommerce.subscriptions.commitment', ['months' => $price['commitment_months']]))?></span>
                            <?php endif; ?>
                            <a class="btn btn-black mt-2" href="<?=e($price['url'])?>"
                               data-select-item="<?=e((string) json_encode(['event' => 'select_item', 'ecommerce' => ['item_list_name' => $listName, 'items' => [$item]]], $flags))?>"><?=e(__t('ecommerce.subscriptions.choose'))?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
    <script>
        document.querySelector('[data-subscription-plans]').addEventListener('click', function (event) {
            var link = event.target.closest('[data-select-item]');
            if (!link) { return; }
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push(JSON.parse(link.getAttribute('data-select-item')));
        });
    </script>
<?php endif; ?>
</article>

<?php View::end(); ?>
