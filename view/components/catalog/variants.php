<?php

use Wonder\Plugin\Gestionale\Models\Catalog\Product;

/** @var array $variants Varianti della scheda: name, url, image, active. */
/** @var bool $hasVariants «Questo articolo ha varianti»: i bottoni anche con una sola. */
/** @var bool $textOnly L'attributo di variante è di tipo «Bottone»: niente miniature. */
$hasVariants ??= false;
$textOnly ??= false;
?>
<?php if (count($variants) > 1 || ($hasVariants && $variants !== [])): ?>
    <div class="w-100">
        <div class="product-variants d-flex flex-wrap gap-3" aria-label="<?=e(__t('ecommerce.catalog.variants'))?>">
            <?php foreach ($variants as $variant): ?>
                <?php if (!$textOnly && !empty($variant['image'])): ?>
                    <a href="<?=e($variant['url'] ?? '')?>" class="product-variant d-block f-1-1 p-2 b-1 <?=!empty($variant['active']) ? 'tx-primary' : ''?>" title="<?=e($variant['name'] ?? '')?>" aria-label="<?=e($variant['name'] ?? '')?>" <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>>
                        <span class="d-block w-100 f-1-1 p-r"><?=Product::image((string) $variant['image'], Product::IMAGE_THUMB, (string) ($variant['name'] ?? ''))->displaySizes('120px')?></span>
                    </a>
                <?php else: ?>
                    <a href="<?=e($variant['url'] ?? '')?>" class="product-variant btn btn-sm <?=!empty($variant['active']) ? 'btn-primary' : 'btn-light'?>" <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>><?=e($variant['name'] ?? '')?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
