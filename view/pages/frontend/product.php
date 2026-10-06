<?php

use Wonder\App\Dependencies;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductDetail;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

if (!$product instanceof ProductDetail) {
    http_response_code(404);
    return;
}

$data = $product->data();
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
$schema = json_encode($product->schemaOrg(), $jsonFlags) ?: '{}';
$images = [];

foreach ($data['images'] as $image) {
    $images[(string) $image['url']] = (string) ($image['alt'] ?: $data['name']);
}

$money = static function (float $value, string $currency): string {
    $symbol = $currency === 'EUR' ? '€' : $currency;
    return number_format($value, 2, ',', '.').' '.$symbol;
};
$prices = array_column($data['offers'], 'price');
$minPrice = $prices !== [] ? (float) min($prices) : 0.0;
$maxPrice = $prices !== [] ? (float) max($prices) : 0.0;
$priceLabel = $minPrice !== $maxPrice
    ? __t('ecommerce.catalog.price_range', [
        'min' => $money($minPrice, $data['currency']),
        'max' => $money($maxPrice, $data['currency']),
    ])
    : $money($minPrice, $data['currency']);

Dependencies::swiper();
Dependencies::fancyapps();
Ecommerce::layout('shop');
?>
<script type="application/ld+json"><?=$schema?></script>
<?=DataLayer::script([
    'type' => 'product',
    'language' => __l(),
    'currency' => $data['currency'],
], $product->viewProductEvent(), (int) ($_SESSION['user_id'] ?? 0))?>

<nav aria-label="<?=e(__t('ecommerce.catalog.breadcrumb_label'))?>" class="mb-5">
    <ol class="d-flex d-p-column gap-2 text-small">
        <?php foreach ($data['breadcrumbs'] as $index => $item): ?>
            <li>
                <?php if ($index === array_key_last($data['breadcrumbs'])): ?>
                    <span aria-current="page"><?=e($item['name'] ?? '')?></span>
                <?php else: ?>
                    <a href="<?=e($item['url'] ?? '')?>"><?=e($item['name'] ?? '')?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>

<article class="d-grid col-2 col-p-1 gap-8">
    <div class="o-hidden">
        <?php if ($images !== []): ?>
            <?=__swiper($images)
                ->id('ecommerce-product-gallery')
                ->ratio('4:5')
                ->thumbnails(count($images) > 1)
                ->thumbsRatio('1:1')
                ->lightbox('ecommerce-product')
                ->navigation(count($images) > 1)
                ->pagination(count($images) > 1)
                ->priority()
                ->imageSizes('(max-width: 768px) 100vw, 45vw')
                ->thumbsImageSizes('120px')?>
        <?php else: ?>
            <div class="f-4-5 bg-light" role="img" aria-label="<?=e($data['name'])?>"></div>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($data['category'] !== ''): ?>
            <a class="badge badge-primary tx-upper" href="<?=e($data['category_url'])?>"><?=e($data['category'])?></a>
        <?php endif; ?>

        <h1 class="title mt-3"><?=e($data['name'])?></h1>

        <?php if ($data['short_description'] !== ''): ?>
            <p class="text mt-3"><?=e($data['short_description'])?></p>
        <?php endif; ?>

        <p class="subtitle fw-600 mt-5"><?=e($priceLabel)?></p>
        <p class="text-small mt-2">
            <?=e(__t($data['available']
                ? 'ecommerce.catalog.availability.in_stock'
                : 'ecommerce.catalog.availability.out_of_stock'))?>
        </p>

        <?php if (count($data['variants']) > 1): ?>
            <div class="mt-6">
                <h2 class="text-small fw-600 mb-2"><?=e(__t('ecommerce.catalog.variants'))?></h2>
                <div class="d-flex gap-2">
                    <?php foreach ($data['variants'] as $variant): ?>
                        <a
                            href="<?=e($variant['url'] ?? '')?>"
                            class="btn btn-sm <?=!empty($variant['active']) ? 'btn-primary' : 'btn-light'?>"
                            <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>
                        ><?=e($variant['name'] ?? '')?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-6">
            <?php if (count($data['offers']) === 1): ?>
                <?php if ($data['available']): ?>
                    <?=View::component(Ecommerce::viewPath('components/cart/add.php'), [
                        'product_id' => $data['selected_product_id'],
                        'csrf_token' => AuthSession::csrfToken(),
                        'label' => __t('ecommerce.cart.add'),
                    ])?>
                <?php else: ?>
                    <button class="btn btn-primary w-100" type="button" disabled><?=e(__t('ecommerce.catalog.availability.out_of_stock'))?></button>
                <?php endif; ?>
            <?php elseif ($data['offers'] !== []): ?>
                <?php
                    $options = [];
                    foreach ($data['offers'] as $offer) {
                        $label = trim((string) $offer['name']) ?: (string) $offer['sku'];
                        $label = ($label !== '' ? $label.' — ' : '').$money((float) $offer['price'], $data['currency']);
                        if (!$offer['available']) {
                            $label .= ' — '.__t('ecommerce.catalog.availability.out_of_stock');
                        }
                        $options[(string) $offer['product_id']] = $label;
                    }
                ?>
                <form id="add_to_cart_product" method="post" action="<?=e(__r('ecommerce.cart.add'))?>" data-ecommerce-add-to-cart>
                    <?=FormField::key('csrf_token')->hidden()->value(AuthSession::csrfToken())?>
                    <?=FormField::key('quantity')->hidden()->value('1')?>
                    <?=FormField::key('continue')->hidden()->value((string) ($_SERVER['REQUEST_URI'] ?? $data['url']))?>
                    <?=FormField::key('product_id')
                        ->select($options)
                        ->label((string) __t('ecommerce.catalog.options'))
                        ->required()
                        ->value((string) $data['selected_product_id'])?>
                    <button class="btn btn-primary w-100 mt-3" type="submit"<?=$data['available'] ? '' : ' disabled'?>><?=e(__t('ecommerce.cart.add'))?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($data['description_html'] !== ''): ?>
            <section class="wi-dropdown-box wi-show mt-6">
                <h2 class="wi-dropdown-title wi-switcher text fw-600"><?=e(__t('ecommerce.catalog.description'))?> <i class="bi bi-dash" aria-hidden="true"></i></h2>
                <div class="wi-dropdown-content">
                    <div class="text-small"><?=$data['description_html']?></div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($data['details'] !== []): ?>
            <section class="wi-dropdown-box mt-3">
                <h2 class="wi-dropdown-title wi-switcher text fw-600"><?=e(__t('ecommerce.catalog.details.title'))?> <i class="bi bi-plus" aria-hidden="true"></i></h2>
                <div class="wi-dropdown-content">
                    <dl class="d-grid col-2 gap-2 text-small">
                        <?php foreach ($data['details'] as $detail): ?>
                            <dt><?=e($detail['label'] ?? '')?></dt>
                            <dd class="a-r"><?=e($detail['value'] ?? '')?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </section>
        <?php endif; ?>
    </div>
</article>

<?php View::end(); ?>
