<?php

/**
 * La pagina pubblica dei piani (G10 §5).
 *
 * SEO: `INDEX,FOLLOW`, un solo titolo h1, piani resi lato server; ogni
 * abbonamento è un prodotto del catalogo e il nome (e la foto) portano alla
 * sua scheda. schema.org: `ItemList` di `Product` con gli stessi importi in
 * pagina e l'`url` della scheda. Breadcrumb:
 * Home › Abbonamenti, a video e in JSON-LD (dal core). dataLayer:
 * gli eventi rimasti in sospeso (`remove_from_cart` quando la selezione si
 * è svuotata), poi `view_item_list` alla vista e `select_item` al clic su
 * «Aggiungi».
 *
 * @var list<array<string, mixed>> $plans
 * @var string $title
 * @var string $page_url
 * @var list<array{url: string, name: string}> $breadcrumbs
 * @var bool $include_tax
 * @var string $currency
 * @var list<array<string, mixed>> $events
 */

use Wonder\Elements\Components\Breadcrumb;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ImageFormat;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\View\View;

$plans = is_array($plans ?? null) ? $plans : [];
$listName = (string) $title;

$SEO->schemaOrg = PlanCatalog::schemaOrg($plans, (string) $page_url, $listName, (string) $currency);

View::head(DataLayer::script([
    'type' => 'subscription_plans',
    'language' => __l(),
    'currency' => $currency,
], [...(is_array($events ?? null) ? $events : []), [
    'event' => 'view_item_list',
    'ecommerce' => [
        'item_list_name' => $listName,
        'currency' => $currency,
        'items' => PlanCatalog::trackingItems($plans),
    ],
]], (int) ($_SESSION['user_id'] ?? 0)));

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
                <?php if ($plan['image'] !== ''): ?>
                    <a class="<?=ImageFormat::className('catalog.card.image_format', '1:1')?> d-block p-r o-hidden bg-light" href="<?=e($plan['url'])?>" tabindex="-1" aria-hidden="true">
                        <?=ImageFormat::fit(Product::image($plan['image'], Product::IMAGE_CARD, (string) $plan['image_alt'])->displaySizes('(max-width: 768px) 100vw, 30vw'), 'catalog.card.image_fit')?>
                    </a>
                <?php endif; ?>
                <h2 class="subtitle" id="piano-<?=e($plan['code'])?>">
                    <?php if ($plan['url'] !== ''): ?><a href="<?=e($plan['url'])?>"><?=e($plan['name'])?></a><?php else: ?><?=e($plan['name'])?><?php endif; ?>
                </h2>
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
                <?=View::component(Ecommerce::viewPath('components/subscriptions/prices.php'), [
                    'plan' => $plan,
                    'currency' => $currency,
                    'include_tax' => $include_tax,
                    'list_name' => $listName,
                    'index' => $index,
                    'direct' => SubscriptionsController::addsDirectly((int) $plan['id']),
                ])?>
                <?php $index += count($plan['prices']); ?>
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
