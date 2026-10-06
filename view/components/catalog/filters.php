<?php

use Wonder\App\ResourceSchema\FormField;

$query = is_array($query ?? null) ? $query : [];
$brands = is_array($brands ?? null) ? $brands : [];
$attributes = is_array($attributes ?? null) ? $attributes : [];
$brandOptions = ['' => (string) __t('ecommerce.catalog.filters.any_brand')];
foreach ($brands as $brand) {
    $brandOptions[(string) ($brand['slug'] ?? '')] = (string) ($brand['name'] ?? '');
}
$orderOptions = [
    '' => (string) __t('ecommerce.catalog.filters.order.default'),
    'recenti' => (string) __t('ecommerce.catalog.filters.order.newest'),
    'prezzo_asc' => (string) __t('ecommerce.catalog.filters.order.price_asc'),
    'prezzo_desc' => (string) __t('ecommerce.catalog.filters.order.price_desc'),
    'nome_asc' => (string) __t('ecommerce.catalog.filters.order.name_asc'),
    'nome_desc' => (string) __t('ecommerce.catalog.filters.order.name_desc'),
];
?>
<form id="catalog_filters" method="get" class="wi-box p-4 d-grid col-1 gap-4" aria-label="<?=e(__t('ecommerce.catalog.filters.label'))?>">
    <?php if (isset($query['q'])): ?>
        <?=FormField::key('q')->hidden()->value((string) $query['q'])?>
    <?php endif; ?>
    <div class="d-grid col-4 col-t-2 col-p-1 gap-3">
        <?=FormField::key('marca')
            ->select($brandOptions)
            ->label((string) __t('ecommerce.catalog.filters.brand'))
            ->value((string) ($query['marca'] ?? ''))?>
        <?=FormField::key('prezzo')
            ->text()
            ->label((string) __t('ecommerce.catalog.filters.price'))
            ->placeholder((string) __t('ecommerce.catalog.filters.price_placeholder'))
            ->value((string) ($query['prezzo'] ?? ''))?>
        <?=FormField::key('ordina')
            ->select($orderOptions)
            ->label((string) __t('ecommerce.catalog.filters.order.label'))
            ->value((string) ($query['ordina'] ?? ''))?>
        <?php foreach ($attributes as $attribute): ?>
            <?php
                $slug = (string) ($attribute['slug'] ?? '');
                $type = (string) ($attribute['type'] ?? 'select');
                $values = is_array($attribute['values'] ?? null) ? $attribute['values'] : [];
                $options = ['' => (string) __t('ecommerce.catalog.filters.any_value')];
                foreach ($values as $value) {
                    $options[(string) ($value['id'] ?? '')] = (string) ($value['label'] ?? '');
                }
                $field = FormField::key($slug)->label((string) ($attribute['name'] ?? $slug));
            ?>
            <?php if (in_array($type, ['select', 'color', 'pattern', 'icon'], true)): ?>
                <?=$field->select($options)->value((string) ($query[$slug] ?? ''))?>
            <?php else: ?>
                <?=$field->text()->value((string) ($query[$slug] ?? ''))?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="d-flex f-wrap gap-2">
        <button class="btn btn-primary" type="submit"><?=e(__t('ecommerce.catalog.filters.apply'))?></button>
        <a class="btn btn-secondary-o" href="<?=e((string) strtok($_SERVER['REQUEST_URI'] ?? '/prodotti/', '?'))?>"><?=e(__t('ecommerce.catalog.filters.reset'))?></a>
    </div>
</form>
