<?php

use Wonder\App\Dependencies;
use Wonder\Elements\Components\Breadcrumb;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductDetail;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

if (!$product instanceof ProductDetail) {
    http_response_code(404);
    return;
}

$data = $product->data();
$SEO->schemaOrg = $product->schemaOrg();
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
$similar_products = is_array($similar_products ?? null) ? $similar_products : [];
$recent_products = is_array($recent_products ?? null) ? $recent_products : [];
$suggested_products = is_array($suggested_products ?? null) ? $suggested_products : [];
$listEvent = static function (string $id, string $name, array $cards) use ($data): array {
    return [
        'event' => 'view_item_list',
        'ecommerce' => [
            'currency' => $data['currency'],
            'item_list_id' => $id,
            'item_list_name' => $name,
            'items' => array_values(array_map(static function (array $card, int $index) use ($id, $name): array {
                $item = [
                    'item_id' => (string) ($card['meta']['item_id'] ?? $card['id'] ?? ''),
                    'item_name' => (string) ($card['meta']['item_name'] ?? $card['name'] ?? ''),
                    'price' => (float) ($card['meta']['price'] ?? 0),
                    'item_list_id' => $id,
                    'item_list_name' => $name,
                    'index' => $index + 1,
                ];
                if (($card['meta']['item_variant'] ?? '') !== '') {
                    $item['item_variant'] = (string) $card['meta']['item_variant'];
                }

                return $item;
            }, $cards, array_keys($cards))),
        ],
    ];
};
$events = [$product->viewProductEvent()];
if ($similar_products !== []) $events[] = $listEvent('similar_products', (string) __t('ecommerce.catalog.sliders.similar'), $similar_products);
if ($recent_products !== []) $events[] = $listEvent('recent_products', (string) __t('ecommerce.catalog.sliders.recent'), $recent_products);
if ($suggested_products !== []) $events[] = $listEvent('suggested_products', (string) __t('ecommerce.catalog.sliders.suggested'), $suggested_products);

Dependencies::swiper();
Dependencies::fancyapps();
View::head(DataLayer::script([
    'type' => 'product',
    'language' => __l(),
    'currency' => $data['currency'],
], $events, (int) ($_SESSION['user_id'] ?? 0)));

Ecommerce::layout('shop');
?>

<?=Breadcrumb::make($data['breadcrumbs'])->class('mb-5')?>

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

    <div class="w-100 d-grid col-1 gap-3">

        <?php if ($data['category'] !== ''): ?>
            <div>
                <a class="badge badge-primary tx-upper" href="<?=e($data['category_url'])?>"><?=e($data['category'])?></a>
            </div>
        <?php endif; ?>

        <h1 class="title"><?=e($data['name'])?></h1>

        <?php if ($data['short_description'] !== ''): ?>
            <p class="text"><?=e($data['short_description'])?></p>
        <?php endif; ?>

        <div>
            <p class="subtitle fw-600 mt-2" data-product-price><?=e($priceLabel)?></p>

            <?php if ($data['stock_managed']): ?>
                <span class="badge badge-light" data-product-availability>
                    <?=$data['available']
                        ? '<i class="bi bi-circle-fill tx-success"></i> '.e(__t('ecommerce.catalog.availability.in_stock'))
                        : '<i class="bi bi-circle-fill tx-danger"></i> '.e(__t('ecommerce.catalog.availability.out_of_stock'))?>
                </span>
            <?php endif; ?>

        </div>

        <?php if (count($data['variants']) > 1): ?>
            <div class="w-100">
                <h2 class="p-r f-start w-100 text-small fw-600 mb-2"><?=e(__t('ecommerce.catalog.variants'))?></h2>
                <div class="d-flex f-wrap gap-3">
                    <?php foreach ($data['variants'] as $variant): ?>
                        <?php if (!empty($variant['image'])): ?>
                            <a href="<?=e($variant['url'] ?? '')?>" class="d-block w-15 f-1-1 p-2 b-1 <?=!empty($variant['active']) ? 'tx-primary' : ''?>" style="border-color:currentColor" title="<?=e($variant['name'] ?? '')?>" aria-label="<?=e($variant['name'] ?? '')?>" <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>>
                                <img src="<?=e($variant['image'])?>" alt="<?=e($variant['name'] ?? '')?>" width="120" height="120" loading="lazy" class="w-100 f-1-1 o-cover">
                            </a>
                        <?php else: ?>
                            <a href="<?=e($variant['url'] ?? '')?>" class="btn btn-sm <?=!empty($variant['active']) ? 'btn-primary' : 'btn-light'?>" <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>><?=e($variant['name'] ?? '')?></a>
                        <?php endif; ?>
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
                <form id="add_to_cart_product" method="post" action="<?=e(__r('ecommerce.cart.add'))?>" data-ecommerce-add-to-cart>
                    <?=FormField::key('csrf_token')->hidden()->value(AuthSession::csrfToken())?>
                    <?=FormField::key('quantity')->hidden()->value('1')?>
                    <?=FormField::key('continue')->hidden()->value((string) ($_SERVER['REQUEST_URI'] ?? $data['url']))?>
                    <?php if ($data['option_groups'] === []): ?>
                        <?php $fallbackOptions = array_column($data['offers'], 'option_name', 'product_id'); ?>
                        <?=FormField::key('product_id')->select($fallbackOptions)->label((string) __t('ecommerce.catalog.options'))->required()->value((string) $data['selected_product_id'])?>
                    <?php else: ?>
                    <?=FormField::key('product_id')->hidden()->value((string) $data['selected_product_id'])?>
                    <div class="d-grid col-1 gap-4" data-product-options>
                        <?php foreach ($data['option_groups'] as $group): ?>
                            <fieldset class="b-0 p-0 m-0" data-option-group="<?=e($group['id'] ?? '')?>">
                                <legend class="text-small fw-600 mb-2"><?=e($group['name'] ?? '')?></legend>
                                <?php if (($group['selector'] ?? '') === 'select'): ?>
                                    <?php $choices = array_column((array) $group['values'], 'label', 'id'); ?>
                                    <?=FormField::key('option_'.($group['id'] ?? ''))->select($choices)->required()?>
                                <?php else: ?>
                                    <div class="d-flex f-wrap gap-2">
                                        <?php foreach ((array) ($group['values'] ?? []) as $value): ?>
                                            <button class="btn btn-light p-3" type="button" data-option-value="<?=e($value['id'] ?? '')?>" aria-pressed="false">
                                                <?php if (!empty($value['image'])): ?><img src="<?=e($value['image'])?>" alt="" width="48" height="48" class="f-1-1 o-cover mr-2"><?php endif; ?>
                                                <?php if (!empty($value['color'])): ?><span aria-hidden="true" class="d-inline-block f-1-1 mr-2" style="width:1.25rem;height:1.25rem;background:<?=e($value['color'])?>"></span><?php endif; ?>
                                                <?=e($value['label'] ?? '')?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <button class="btn btn-primary w-100 mt-3" type="submit" data-product-submit<?=$data['available'] ? '' : ' disabled'?>><?=e(__t('ecommerce.cart.add'))?></button>
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

<?php if ($similar_products !== []): ?><section class="mt-10"><?=ProductList::make($similar_products)->title(__t('ecommerce.catalog.sliders.similar'))->renderSwiper()?></section><?php endif; ?>
<?php if ($recent_products !== []): ?><section class="mt-10"><?=ProductList::make($recent_products)->title(__t('ecommerce.catalog.sliders.recent'))->renderSwiper()?></section><?php endif; ?>
<?php if ($suggested_products !== []): ?><section class="mt-10"><?=ProductList::make($suggested_products)->title(__t('ecommerce.catalog.sliders.suggested'))->renderSwiper()?></section><?php endif; ?>

<?php if (count($data['offers']) > 1 && $data['option_groups'] !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('add_to_cart_product');
    if (!form) return;
    const offers = <?=json_encode($data['offers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
    const selected = {};
    const initial = offers.find(offer => Number(offer.product_id) === <?=json_encode((int) $data['selected_product_id'], JSON_HEX_TAG)?>) || offers[0];
    Object.assign(selected, initial.attributes || {});
    form.querySelectorAll('[data-option-group]').forEach(group => {
        const select = group.querySelector('select');
        if (select && selected[group.dataset.optionGroup]) select.value = selected[group.dataset.optionGroup];
    });
    const sync = function () {
        form.querySelectorAll('[data-option-group]').forEach(group => {
            const id = group.dataset.optionGroup;
            const select = group.querySelector('select');
            if (select) {
                selected[id] = select.value;
            }
            group.querySelectorAll('[data-option-value]').forEach(button => {
                const active = button.dataset.optionValue === selected[id];
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.classList.toggle('btn-primary', active);
                button.classList.toggle('btn-light', !active);
            });
        });
        const offer = offers.find(item => Object.entries(selected).every(([key, value]) => String((item.attributes || {})[key] || '') === String(value)));
        const product = form.querySelector('[name="product_id"]');
        const submit = form.querySelector('[data-product-submit]');
        const price = document.querySelector('[data-product-price]');
        const availability = document.querySelector('[data-product-availability]');
        if (product) product.value = offer ? offer.product_id : '';
        if (submit) submit.disabled = !offer || !offer.available;
        if (price && offer) price.textContent = new Intl.NumberFormat(document.documentElement.lang || 'it', { style: 'currency', currency: <?=json_encode($data['currency'], JSON_HEX_TAG)?> }).format(offer.price);
        if (availability && offer) availability.innerHTML = offer.available
            ? '<i class="bi bi-circle-fill tx-success"></i> ' + <?=json_encode((string) __t('ecommerce.catalog.availability.in_stock'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>
            : '<i class="bi bi-circle-fill tx-danger"></i> ' + <?=json_encode((string) __t('ecommerce.catalog.availability.out_of_stock'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
    };
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', sync));
    form.querySelectorAll('[data-option-value]').forEach(button => button.addEventListener('click', function () {
        selected[button.closest('[data-option-group]').dataset.optionGroup] = button.dataset.optionValue;
        sync();
    }));
    sync();
});
</script>
<?php endif; ?>

<?php View::end(); ?>
