<?php

use Wonder\App\Dependencies;
use Wonder\Elements\Components\Breadcrumb;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductDetail;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCheckout;
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
// Il prezzo pieno barrato, se l'opzione mostrata è in offerta. Con una forbice
// di prezzi lo scrive lo script quando la scelta individua un'opzione.
$shownOffer = current(array_filter($data['offers'], static fn (array $offer): bool => $offer['product_id'] === $data['selected_product_id'])) ?: null;
$regularPrice = $minPrice === $maxPrice && $shownOffer !== null && $shownOffer['regular_price'] > $shownOffer['price']
    ? (float) $shownOffer['regular_price']
    : 0.0;
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

$productStyle = module_asset('ecommerce', 'css/product.css');
if ($productStyle !== '') View::head('<link rel="stylesheet" href="'.e($productStyle).'">');
if ((bool) Ecommerce::config('product.express', true) && ExpressCheckout::available() && ($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
    View::head('<script src="'.e($checkoutJs).'" defer></script>');
}

// Il sito decide se la scheda ha la quantità con −/+ e se mostra i bottoni rapidi.
$stepper = (bool) Ecommerce::config('product.quantity', false);
$expressHere = (bool) Ecommerce::config('product.express', true);

// Il foglio dei bottoni rapidi parte dal prezzo dell'offerta caricata
$expressOffer = current(array_filter($data['offers'], fn ($offer) => (int) $offer['product_id'] === (int) $data['selected_product_id'])) ?: ($data['offers'][0] ?? []);
// L'opzione caricata decide i controlli già nell'HTML: anche senza JS il
// cliente vede quella che il form invia, e se è esaurita non la compra.
$selectedAttributes = (array) ($expressOffer['attributes'] ?? []);
$selectedAvailable = (bool) ($expressOffer['available'] ?? false);

// Multiprodotto: i gruppi di scelta vanno nel form d'acquisto. Il check-out
// rapido manda solo il prodotto, senza scelte: con dei gruppi resta spento.
$bundle = $data['bundle'];
$bundleGroups = $bundle['groups'];
if ($bundleGroups !== []) $expressHere = false;
$quantityLabel = static fn (float $quantity): string => rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');
// Pezzi e opzioni sono righe `wi-choice` della lib, come i metodi di pagamento:
// foto a sinistra se c'è, il modello e sotto la sua opzione («Blu · XL»), il
// supplemento in alto a destra. I pezzi fissi non hanno input.
$bundleRow = static function (string $tag, string $input, string $image, string $name, array $details, string $aside = ''): string {
    [$title, $option] = CartPresenter::split($name);
    array_unshift($details, $option);
    $picture = $image !== ''
        ? '<img src="'.e($image).'" alt="" width="48" height="48" loading="lazy" class="o-cover" data-bundle-thumb>'
        : '';
    $details = array_values(array_filter($details, 'strlen'));

    return '<'.$tag.' class="wi-choice">'.$input.$picture
        .'<span class="wi-choice__body"><span class="wi-choice__title">'.e($title).'</span>'
        .($details !== [] ? '<span class="wi-choice__text">'.e(implode(' · ', $details)).'</span>' : '')
        .'</span>'
        .($aside !== '' ? '<span class="wi-choice__aside">'.e($aside).'</span>' : '')
        .'</'.$tag.'>';
};
$bundleFields = '';
foreach ($bundleGroups as $group) {
    $hint = match (true) {
        $group['min'] === $group['max'] => __t('ecommerce.catalog.bundle.choose_exactly', ['count' => $group['min']]),
        $group['min'] === 0 => __t('ecommerce.catalog.bundle.choose_up_to', ['max' => $group['max']]),
        default => __t('ecommerce.catalog.bundle.choose_between', ['min' => $group['min'], 'max' => $group['max']]),
    };
    // Una sola scelta obbligata è un radio; il resto checkbox, col tetto dal JS.
    $single = $group['min'] === 1 && $group['max'] === 1;
    $rows = '';
    foreach ($group['options'] as $option) {
        $input = '<input type="'.($single ? 'radio' : 'checkbox').'" name="choices_'.$group['id'].($single ? '' : '[]').'" value="'.$option['id'].'"'.($single ? ' required' : '').'>';
        $rows .= $bundleRow('label', $input, $option['image'], $option['name'], [], $option['surcharge'] > 0 ? '+'.$money($option['surcharge'], $data['currency']) : '');
    }
    $bundleFields .= '<fieldset class="wi-choice-group wi-choice-group--list" data-bundle-group="'.e((string) $group['id']).'" data-min="'.$group['min'].'" data-max="'.$group['max'].'">'
        .'<legend class="wi-choice-group__legend text-small fw-400 mb-2">'.e($group['name'].' · '.(string) $hint).($group['min'] > 0 ? ' *' : '').'</legend>'
        .'<div class="wi-choice-group__list">'.$rows.'</div>'
        .'</fieldset>';
}
if ($bundleFields !== '') $bundleFields = '<div class="w-100 d-grid col-1 gap-4 mb-3" data-bundle-groups>'.$bundleFields.'</div>';

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

    <div class="product-details w-100 d-grid col-1 gap-3">

        <?php if ($data['category'] !== '' || $data['stock_managed']): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($data['category'] !== ''): ?>
                    <a class="badge badge-primary tx-upper" href="<?=e($data['category_url'])?>"><?=e($data['category'])?></a>
                <?php endif; ?>
                <?php if ($data['stock_managed']): ?>
                    <span class="badge badge-light" data-product-availability>
                        <?=$data['available']
                            ? '<i class="bi bi-circle-fill tx-success"></i> '.e(__t('ecommerce.catalog.availability.in_stock'))
                            : '<i class="bi bi-circle-fill tx-danger"></i> '.e(__t('ecommerce.catalog.availability.out_of_stock'))?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h1 class="title"><?=e($data['name'])?></h1>

        <?php if ($data['short_description'] !== ''): ?>
            <p class="text"><?=e($data['short_description'])?></p>
        <?php endif; ?>

        <div>
            <p class="subtitle fw-600 mt-2"><span data-product-price><?=e($priceLabel)?></span> <s class="text fw-400 tx-dark ml-2" data-product-regular<?=$regularPrice > 0 ? '' : ' hidden'?>><?=$regularPrice > 0 ? e($money($regularPrice, $data['currency'])) : ''?></s></p>

            <?php if ($bundle['value'] !== null): ?>
                <p class="text-small tx-dark mt-2"><?=e(__t($bundleGroups !== [] ? 'ecommerce.catalog.bundle.value_from' : 'ecommerce.catalog.bundle.value', ['price' => $money($bundle['value'], $data['currency'])]))?></p>
            <?php endif; ?>
        </div>

        <?=View::component(Ecommerce::viewPath('components/catalog/variants.php'), ['variants' => $data['variants']])?>

        <?php if ($bundle['components'] !== []): ?>
            <div class="w-100 wi-choice-group wi-choice-group--list" data-bundle-contents>
                <h2 class="wi-choice-group__legend text-small fw-400 mb-2"><?=e(__t('ecommerce.catalog.bundle.contents'))?></h2>
                <div class="wi-choice-group__list">
                    <?php foreach ($bundle['components'] as $component): ?>
                        <?=$bundleRow('div', '', $component['image'], $component['name'], [
                            (string) __t('ecommerce.catalog.bundle.quantity', ['count' => $quantityLabel($component['quantity'])]),
                        ])?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="<?=$bundleGroups !== [] ? 'mt-2' : 'mt-6'?>">
            <?php if (count($data['offers']) === 1): ?>
                <?php if ($data['available']): ?>
                    <?=View::component(Ecommerce::viewPath('components/cart/add.php'), [
                        'product_id' => $data['selected_product_id'],
                        'csrf_token' => AuthSession::csrfToken(),
                        'label' => __t('ecommerce.cart.add'),
                        'stepper' => $stepper,
                        'fields' => $bundleFields,
                    ])?>
                <?php else: ?>
                    <button class="btn btn-primary w-100 wi-input-submit" type="button" disabled><?=e(__t('ecommerce.catalog.availability.out_of_stock'))?></button>
                <?php endif; ?>
            <?php elseif ($data['offers'] !== []): ?>
                <form id="add_to_cart_product" method="post" action="<?=e(__r('ecommerce.cart.add'))?>" data-ecommerce-add-to-cart>
                    <?=FormField::key('csrf_token')->hidden()->value(AuthSession::csrfToken())?>
                    <?php if (!$stepper): ?><?=FormField::key('quantity')->hidden()->value('1')?><?php endif; ?>
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
                                    <?=FormField::key('option_'.($group['id'] ?? ''))->select($choices)->required()->value((string) ($selectedAttributes[$group['id'] ?? ''] ?? ''))?>
                                <?php else: ?>
                                    <div class="d-flex f-wrap gap-2">
                                        <?php foreach ((array) ($group['values'] ?? []) as $value): ?>
                                            <?php $pressed = (string) ($value['id'] ?? '') === (string) ($selectedAttributes[$group['id'] ?? ''] ?? ''); ?>
                                            <button class="btn <?=$pressed ? 'btn-primary' : 'btn-light'?> p-3" type="button" data-option-value="<?=e($value['id'] ?? '')?>" aria-pressed="<?=$pressed ? 'true' : 'false'?>">
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
                    <?=$bundleFields?>
                    <div class="w-100 d-flex gap-3 mt-3">
                        <?php if ($stepper): ?><?=View::component(Ecommerce::viewPath('components/cart/quantity.php'))?><?php endif; ?>
                        <button class="btn btn-primary w-100 wi-input-submit" type="submit" data-product-submit data-label-available="<?=e(__t('ecommerce.cart.add'))?>" data-label-unavailable="<?=e(__t('ecommerce.catalog.availability.out_of_stock'))?>"<?=$selectedAvailable ? '' : ' disabled'?>><?=e($selectedAvailable ? __t('ecommerce.cart.add') : __t('ecommerce.catalog.availability.out_of_stock'))?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($expressHere): ?>
            <div data-express-product<?=$selectedAvailable ? '' : ' hidden'?>>
                <?=ExpressCheckout::render('product', ['total' => $expressOffer['price'] ?? 0, 'currency' => $data['currency']], (int) $data['selected_product_id'])?>
            </div>
        <?php endif; ?>

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

<?php if (count($data['offers']) > 1): ?>
<?php
$optionSlugs = [];
foreach ($data['option_groups'] as $group) {
    $optionSlugs[(string) ($group['id'] ?? '')] = [
        'slug' => (string) ($group['slug'] ?? ''),
        'values' => array_column((array) ($group['values'] ?? []), 'slug', 'id'),
    ];
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('add_to_cart_product');
    if (!form) return;
    const offers = <?=json_encode($data['offers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
    const selected = {};
    const slugs = <?=json_encode($optionSlugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT)?>;
    // Solo dopo una scelta del cliente: all'apertura la barra resta com'è.
    const remember = function () {
        if (Object.keys(slugs).length === 0) return;
        const url = new URL(window.location.href);
        Object.entries(slugs).forEach(([id, group]) => {
            if (!group.slug) return;
            const value = selected[id] ? (group.values[selected[id]] || selected[id]) : '';
            if (value) url.searchParams.set(group.slug, value);
            else url.searchParams.delete(group.slug);
        });
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    };
    const variantLinks = Array.from(document.querySelectorAll('.product-variants a'));
    const updateVariantLinks = function () {
        if (Object.keys(slugs).length === 0) return;
        variantLinks.forEach(link => {
            const url = new URL(link.href, window.location.href);
            Object.entries(slugs).forEach(([id, group]) => {
                if (!group.slug) return;
                const value = selected[id] ? (group.values[selected[id]] || selected[id]) : '';
                if (value) url.searchParams.set(group.slug, value);
                else url.searchParams.delete(group.slug);
            });
            link.href = url.href;
        });
    };
    variantLinks.forEach(link => link.addEventListener('click', function (event) {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target !== '_self') return;
        event.preventDefault();
        if (link.getAttribute('aria-current') === 'page') return;
        // I colori condividono una sola voce: Indietro torna alla pagina di ingresso.
        window.location.replace(link.href);
    }));
    const initial = offers.find(offer => Number(offer.product_id) === <?=json_encode((int) $data['selected_product_id'], JSON_HEX_TAG)?>) || offers[0];
    Object.assign(selected, initial.attributes || {});
    // Il select della lib aggiorna il testo mostrato solo al «change»: va
    // lanciato qui, prima di agganciare i listener, così l'URL non cambia.
    form.querySelectorAll('[data-option-group]').forEach(group => {
        const select = group.querySelector('select');
        if (!select || !selected[group.dataset.optionGroup]) return;
        select.value = selected[group.dataset.optionGroup];
        select.dispatchEvent(new Event('change'));
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
        updateVariantLinks();
        const product = form.querySelector('[name="product_id"]');
        // Senza gruppi di opzioni il select del prodotto sceglie l'offerta.
        const offer = form.querySelector('[data-option-group]')
            ? offers.find(item => Object.entries(selected).every(([key, value]) => String((item.attributes || {})[key] || '') === String(value)))
            : offers.find(item => String(item.product_id) === String(product?.value || ''));
        const submit = form.querySelector('[data-product-submit]');
        const price = document.querySelector('[data-product-price]');
        const availability = document.querySelector('[data-product-availability]');
        if (product && product.type === 'hidden') product.value = offer ? offer.product_id : '';
        if (submit) {
            submit.disabled = !offer || !offer.available;
            submit.textContent = submit.disabled ? submit.dataset.labelUnavailable : submit.dataset.labelAvailable;
        }
        const expressBox = document.querySelector('[data-express-product]');
        if (expressBox) expressBox.hidden = !offer || !offer.available;
        // Il foglio rapido si apre col prezzo della variante scelta.
        const expressRoot = expressBox?.querySelector('[data-express]');
        if (expressRoot && offer) {
            expressRoot.dataset.expressUnit = Math.round(offer.price * 100);
            expressRoot.dispatchEvent(new Event('express:amount'));
        }
        const format = new Intl.NumberFormat(document.documentElement.lang || 'it', { style: 'currency', currency: <?=json_encode($data['currency'], JSON_HEX_TAG)?> });
        if (price && offer) price.textContent = format.format(offer.price);
        const regular = document.querySelector('[data-product-regular]');
        if (regular && offer) {
            regular.hidden = !(offer.regular_price > offer.price);
            regular.textContent = regular.hidden ? '' : format.format(offer.regular_price);
        }
        if (availability && offer) availability.innerHTML = offer.available
            ? '<i class="bi bi-circle-fill tx-success"></i> ' + <?=json_encode((string) __t('ecommerce.catalog.availability.in_stock'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>
            : '<i class="bi bi-circle-fill tx-danger"></i> ' + <?=json_encode((string) __t('ecommerce.catalog.availability.out_of_stock'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
    };
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', function () {
        sync();
        remember();
    }));
    form.querySelectorAll('[data-option-value]').forEach(button => button.addEventListener('click', function () {
        selected[button.closest('[data-option-group]').dataset.optionGroup] = button.dataset.optionValue;
        sync();
        remember();
    }));
    // Al ritorno dalla cache del browser, riallinea link e controlli ripristinati.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) sync();
    });
    sync();
});
</script>
<?php endif; ?>

<?php if ($bundleGroups !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const box = document.querySelector('[data-bundle-groups]');
    const form = box?.closest('form');
    if (!form) return;
    // Il server riconvalida tutto (Bundles::resolve): qui si guida la scelta.
    const prices = <?=json_encode(array_column($data['offers'], 'price', 'product_id'), JSON_HEX_TAG | JSON_FORCE_OBJECT)?>;
    const regulars = <?=json_encode(array_column($data['offers'], 'regular_price', 'product_id'), JSON_HEX_TAG | JSON_FORCE_OBJECT)?>;
    const available = <?=json_encode(array_column($data['offers'], 'available', 'product_id'), JSON_HEX_TAG | JSON_FORCE_OBJECT)?>;
    const surcharges = <?=json_encode(array_column(array_merge(...array_column($bundleGroups, 'options')), 'surcharge', 'id'), JSON_HEX_TAG | JSON_FORCE_OBJECT)?>;
    const money = new Intl.NumberFormat(document.documentElement.lang || 'it', { style: 'currency', currency: <?=json_encode($data['currency'], JSON_HEX_TAG)?> });
    const groups = Array.from(box.querySelectorAll('[data-bundle-group]'));
    const update = function () {
        let complete = true;
        let cents = 0;
        groups.forEach(group => {
            const inputs = Array.from(group.querySelectorAll('input[type="checkbox"], input[type="radio"]'));
            const checked = inputs.filter(input => input.checked);
            const min = Number(group.dataset.min);
            const max = Number(group.dataset.max);
            if (checked.length < min) complete = false;
            group.toggleAttribute('data-incomplete', checked.length < min);
            inputs.forEach(input => {
                if (input.type === 'checkbox') input.disabled = !input.checked && checked.length >= max;
            });
            checked.forEach(input => { cents += Math.round(Number(surcharges[input.value] || 0) * 100); });
        });
        const productId = String(form.querySelector('[name="product_id"]')?.value || '');
        const price = document.querySelector('[data-product-price]');
        if (price && productId in prices) price.textContent = money.format((Math.round(Number(prices[productId]) * 100) + cents) / 100);
        // Anche il prezzo pieno porta i sovrapprezzi: lo sconto resta quello dell'opzione.
        const regular = document.querySelector('[data-product-regular]');
        if (regular && productId in prices) {
            regular.hidden = !(Number(regulars[productId]) > Number(prices[productId]));
            regular.textContent = regular.hidden ? '' : money.format((Math.round(Number(regulars[productId]) * 100) + cents) / 100);
        }
        const submit = form.querySelector('[type="submit"]');
        if (submit) submit.disabled = !complete || !(productId in available) || !available[productId];
    };
    // Dopo il sync delle opzioni del prodotto, che riscrive prezzo e bottone.
    form.addEventListener('change', () => setTimeout(update));
    form.addEventListener('click', event => { if (event.target.closest('[data-option-value]')) setTimeout(update); });
    window.addEventListener('pageshow', event => { if (event.persisted) setTimeout(update); });
    update();
});
</script>
<?php endif; ?>

<?php View::end(); ?>
