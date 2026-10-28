<?php
use Wonder\Elements\Components\Button;

$navigation = ($navigation ?? null) !== null ? (string) $navigation : null;
?>
<?php if (($title ?? null) !== null || ($subtitle ?? null) !== null || ($text ?? null) !== null || $navigation !== null): ?>
    <div class="product-list__header mb-6 w-100 d-flex d-p-column gap-4" style="align-items: center">
        <div class="w-100">
            <?php if (($title ?? null) !== null): ?>
                <h2 class="title tx-primary"><?=e($title)?></h2>
            <?php endif; ?>
            <?php if (($subtitle ?? null) !== null): ?>
                <p class="text tx-secondary mt-2"><?=e($subtitle)?></p>
            <?php endif; ?>
            <?php if (($text ?? null) !== null): ?>
                <div class="text mt-2"><?=$text?></div>
            <?php endif; ?>
        </div>
        <?php if ($navigation !== null): ?>
            <?php // Accanto al titolo; da telefono sotto, a destra: il margine auto la spinge in fondo in tutti e due i versi. ?>
            <div class="d-flex gap-2" style="margin-left: auto; flex-shrink: 0" data-product-list-nav="<?=e($navigation)?>">
                <?=Button::make()->icon('bi bi-chevron-left', 'start')->variant('black')->size('sm')->class('btn-icon')->attr('aria-label', (string) __t('ecommerce.catalog.sliders.previous'))->attr('data-product-list-prev', '')?>
                <?=Button::make()->icon('bi bi-chevron-right', 'start')->variant('black')->size('sm')->class('btn-icon')->attr('aria-label', (string) __t('ecommerce.catalog.sliders.next'))->attr('data-product-list-next', '')?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
