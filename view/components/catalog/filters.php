<?php

use Wonder\App\ResourceSchema\FormField;

$query = is_array($query ?? null) ? $query : [];
$brands = is_array($brands ?? null) ? $brands : [];
$attributes = is_array($attributes ?? null) ? $attributes : [];
$priceBounds = is_array($price_bounds ?? null) ? $price_bounds : ['min' => 0, 'max' => 0];
$selectedPrice = is_array($selected_price ?? null) ? $selected_price : ['min' => null, 'max' => null];
$formId = trim((string) ($form_id ?? 'catalog_filters')) ?: 'catalog_filters';
$currency = strtoupper(trim((string) ($currency ?? 'EUR')));
$currencySymbol = match ($currency) {
    'EUR' => '€',
    'USD' => '$',
    'GBP' => '£',
    default => $currency,
};
$maxPrice = max(0.0, (float) ($priceBounds['max'] ?? 0));
$priceLabel = number_format($maxPrice, $maxPrice === floor($maxPrice) ? 0 : 2, ',', '.').' '.$currencySymbol;
$asList = static function (mixed $value): array {
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $values = array_values(array_filter(array_map('strval', $values), static fn (string $item): bool => trim($item) !== ''));

    return array_map(
        static fn (string $item): int|string => ctype_digit($item) ? (int) $item : $item,
        $values
    );
};
$brandOptions = [];
foreach ($brands as $brand) {
    $slug = trim((string) ($brand['slug'] ?? ''));
    if ($slug !== '') $brandOptions[$slug] = (string) ($brand['name'] ?? '');
}
$orderOptions = [
    '' => (string) __t('ecommerce.catalog.filters.order.default'),
    'recenti' => (string) __t('ecommerce.catalog.filters.order.newest'),
    'prezzo_desc' => (string) __t('ecommerce.catalog.filters.order.price_desc'),
    'prezzo_asc' => (string) __t('ecommerce.catalog.filters.order.price_asc'),
    'nome_asc' => (string) __t('ecommerce.catalog.filters.order.name_asc'),
    'nome_desc' => (string) __t('ecommerce.catalog.filters.order.name_desc'),
];

$openGroup = static function (string $title, bool $selected = false): void { ?>
    <div class="wi-dropdown-box p-0<?=$selected ? ' wi-show' : ''?>" style="--dropdown-border-width: 0px">
        <div class="wi-dropdown-title wi-switcher text">
            <?=e($title)?> <i class="bi bi-chevron-<?=$selected ? 'up' : 'down'?>" aria-hidden="true"></i>
        </div>
        <div class="wi-dropdown-content">
<?php };
$closeGroup = static function (): void { ?>
        </div>
    </div>
<?php };
?>
<form id="<?=e($formId)?>" method="get" class="wi-box d-grid col-1 gap-4 w-100" aria-label="<?=e(__t('ecommerce.catalog.filters.label'))?>">
    <?php if (isset($query['q'])): ?>
        <?=FormField::key('q')->hidden()->value((string) $query['q'])?>
    <?php endif; ?>

    <?php $openGroup((string) __t('ecommerce.catalog.filters.order.label'), $asList($query['ordina'] ?? null) !== []); ?>
        <?=FormField::key('ordina')
            ->radio($orderOptions)
            ->label('')
            ->value((string) ($query['ordina'] ?? ''))?>
    <?php $closeGroup(); ?>

    <?php if ($maxPrice > 0): ?>
        <?php $openGroup((string) __t('ecommerce.catalog.filters.price'), $selectedPrice['min'] !== null || $selectedPrice['max'] !== null); ?>
            <p class="text-small mb-3"><?=e(__t('ecommerce.catalog.filters.price_range', ['max' => $priceLabel]))?></p>
            <div class="d-grid col-2 col-p-1 gap-3">
                <?=FormField::key('prezzo_da')
                    ->price()
                    ->decimal(2)->decimalSeparator(',')->groupSeparator('.')->symbol($currencySymbol)
                    ->label((string) __t('ecommerce.catalog.filters.price_from'))
                    ->attribute('inputmode="decimal" data-min="0" data-max="'.e((string) $maxPrice).'"')
                    ->value($selectedPrice['min'] ?? '')?>
                <?=FormField::key('prezzo_a')
                    ->price()
                    ->decimal(2)->decimalSeparator(',')->groupSeparator('.')->symbol($currencySymbol)
                    ->label((string) __t('ecommerce.catalog.filters.price_to'))
                    ->attribute('inputmode="decimal" data-min="0" data-max="'.e((string) $maxPrice).'"')
                    ->value($selectedPrice['max'] ?? '')?>
            </div>
        <?php $closeGroup(); ?>
    <?php endif; ?>

    <?php if (count($brandOptions) > 1): ?>
        <?php $openGroup((string) __t('ecommerce.catalog.filters.brand'), $asList($query['marca'] ?? null) !== []); ?>
            <?=FormField::key('marca')
                ->checkbox()
                ->options($brandOptions)
                ->label('')
                ->value($asList($query['marca'] ?? null))?>
        <?php $closeGroup(); ?>
    <?php endif; ?>

    <?php foreach ($attributes as $attribute): ?>
        <?php
            $slug = trim((string) ($attribute['slug'] ?? ''));
            $type = (string) ($attribute['type'] ?? 'select');
            $values = is_array($attribute['values'] ?? null) ? $attribute['values'] : [];
            if ($slug === '' || (in_array($type, ['select', 'color', 'pattern', 'icon'], true) && $values === [])) continue;
            $options = [];
            foreach ($values as $value) {
                $options[(string) ($value['id'] ?? '')] = (string) ($value['label'] ?? '');
            }
            $label = (string) ($attribute['name'] ?? $slug);
            $isCollection = in_array(mb_strtolower($slug), ['collezione', 'collezioni', 'collection', 'collections'], true)
                || in_array(mb_strtolower($label), ['collezione', 'collezioni', 'collection', 'collections'], true);
            if ($isCollection && count($options) <= 1) continue;
            $field = FormField::key($slug)->label($label);
        ?>
        <?php $openGroup($label, $asList($query[$slug] ?? null) !== []); ?>
            <?php if (in_array($type, ['select', 'color', 'pattern', 'icon'], true)): ?>
                <?=$field->checkbox()->options($options)->label('')->value($asList($query[$slug] ?? null))?>
            <?php elseif ($type === 'number'): ?>
                <?=$field->number()->value((string) ($query[$slug] ?? ''))?>
            <?php else: ?>
                <?=$field->text()->value((string) ($query[$slug] ?? ''))?>
            <?php endif; ?>
        <?php $closeGroup(); ?>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mt-2">
        <a class="btn btn-black-o" href="<?=e((string) strtok($_SERVER['REQUEST_URI'] ?? '/prodotti/', '?'))?>"><?=e(__t('ecommerce.catalog.filters.reset'))?></a>
        <button class="btn btn-primary w-100" type="submit"><?=e(__t('ecommerce.catalog.filters.apply'))?></button>
    </div>
</form>
