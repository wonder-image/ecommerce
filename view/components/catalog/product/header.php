<?php
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;

// Stringhe vuote dal backend = campo assente
$title = trim((string) ($title ?? ''));
$subtitle = trim((string) ($subtitle ?? ''));
$text = trim((string) ($text ?? ''));
$navigation = ($navigation ?? null) !== null ? (string) $navigation : null;
$action = is_array($action ?? null) ? $action : null;
$arrows = (string) Ecommerce::config('catalog.swiper.button', 'black');
$button = (string) Ecommerce::config('catalog.action.button', 'black');
?>
<?php if ($title !== '' || $subtitle !== '' || $text !== '' || $navigation !== null || $action !== null): ?>
    <div class="product-list__header mb-6 w-100 d-flex d-p-column gap-4" style="align-items: center">
        <div class="w-100">
            <?php if ($title !== ''): ?>
                <h2 class="title tx-primary"><?=e($title)?></h2>
            <?php endif; ?>
            <?php if ($subtitle !== ''): ?>
                <p class="text tx-secondary mt-2"><?=e($subtitle)?></p>
            <?php endif; ?>
            <?php if ($text !== ''): ?>
                <div class="text mt-2"><?=$text?></div>
            <?php endif; ?>
        </div>
        <?php if ($navigation !== null || $action !== null): ?>
            <?php // Accanto al titolo; da telefono sotto, a destra: il margine auto la spinge in fondo in tutti e due i versi.
            // Con frecce e bottone, da telefono prende la riga: frecce a sinistra, bottone a destra. ?>
            <div class="d-flex gap-2<?=$navigation !== null && $action !== null ? ' w-p-100' : ''?>" style="margin-left: auto; flex-shrink: 0; align-items: center">
                <?php if ($navigation !== null): ?>
                    <div class="d-flex gap-2" data-product-list-nav="<?=e($navigation)?>">
                        <?=Button::make()->icon('bi bi-chevron-left', 'start')->variant($arrows)->size('sm')->class('btn-icon')->attr('aria-label', (string) __t('ecommerce.catalog.sliders.previous'))->attr('data-product-list-prev', '')?>
                        <?=Button::make()->icon('bi bi-chevron-right', 'start')->variant($arrows)->size('sm')->class('btn-icon')->attr('aria-label', (string) __t('ecommerce.catalog.sliders.next'))->attr('data-product-list-next', '')?>
                    </div>
                <?php endif; ?>
                <?php if ($action !== null): ?>
                    <?=Button::to((string) $action['url'], (string) $action['label'])->variant($button)->size('sm')->attr('style', 'margin-left: auto')?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
