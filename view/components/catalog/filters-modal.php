<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

?>
<section id="catalog-filters-mobile" class="wi-modal no-interaction pc-none" role="dialog" aria-modal="true" aria-hidden="true" inert aria-labelledby="catalog-filters-mobile-title">
    <div class="bg wi-close-modal"></div>
    <div class="content wi-modal-content c-w p-r">
        <div class="wi-modal-header">
            <h2 id="catalog-filters-mobile-title" class="wi-modal-title"><?=e(__t('ecommerce.catalog.filters.label'))?></h2>
            <button type="button" class="wi-modal-close wi-close-modal" aria-label="<?=e(__t('ecommerce.catalog.filters.close'))?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="wi-modal-body no-scrollbar">
            <?=View::component(Ecommerce::viewPath('components/catalog/filters.php'), [
                'form_id' => 'catalog_filters_mobile_form',
                'query' => $query ?? [],
                'brands' => $brands ?? [],
                'attributes' => $attributes ?? [],
                'price_bounds' => $price_bounds ?? [],
                'selected_price' => $selected_price ?? [],
                'currency' => $currency ?? 'EUR',
            ])?>
        </div>
    </div>
</section>
