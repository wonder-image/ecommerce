<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;

?>
<section id="search-input" class="wi-modal p-f full-page bg-primary-20 blur-2 no-interaction" style="z-index: 1101" role="dialog" aria-modal="true" aria-hidden="true" inert aria-label="<?=e(__t('ecommerce.catalog.search.open'))?>">
    <div class="bg wi-close-modal"></div>
    <div class="content">
        <div class="bg wi-close-modal"></div>
        <form id="search_product" action="<?=e(__r('ecommerce.catalog.search'))?>" method="get" class="p-r f-start c-w w-60 w-p-100 d-grid col-5 gap-3">
            <div class="col-4">
                <?=FormField::key('q')->searchText(__r('api.ecommerce.catalog.products.search'))
                    ->label((string) __t('ecommerce.catalog.search.label'))
                    ->attribute('maxlength="120" autocomplete="off"')
                    ->required()?>
            </div>
            <div class="col-1">
                <?=Button::make((string) __t('ecommerce.catalog.search.submit'))->type('submit')->variant('primary')->class('wi-input-submit w-100')?>
            </div>
        </form>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('search_product');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        const input = form.querySelector('[name="q"]');
        const selected = form.querySelector('.wi-input-list input:checked');
        if (!input || !selected || input.value !== selected.dataset.wiName) return;
        const url = new URL(selected.value, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        window.location.assign(url.href);
    });
});
</script>
