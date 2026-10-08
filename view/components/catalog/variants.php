<?php
/** @var array $variants Varianti della scheda: name, url, image, active. */
?>
<?php if (count($variants) > 1): ?>
    <div class="w-100">
        <div class="product-variants d-flex f-wrap gap-3" aria-label="<?=e(__t('ecommerce.catalog.variants'))?>">
            <?php foreach ($variants as $variant): ?>
                <?php if (!empty($variant['image'])): ?>
                    <a href="<?=e($variant['url'] ?? '')?>" class="product-variant d-block f-1-1 p-2 b-1 <?=!empty($variant['active']) ? 'tx-primary' : ''?>" title="<?=e($variant['name'] ?? '')?>" aria-label="<?=e($variant['name'] ?? '')?>" <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>>
                        <img src="<?=e($variant['image'])?>" alt="<?=e($variant['name'] ?? '')?>" width="120" height="120" loading="lazy" class="w-100 f-1-1 o-cover">
                    </a>
                <?php else: ?>
                    <a href="<?=e($variant['url'] ?? '')?>" class="product-variant btn btn-sm <?=!empty($variant['active']) ? 'btn-primary' : 'btn-light'?>" <?=!empty($variant['active']) ? 'aria-current="page"' : ''?>><?=e($variant['name'] ?? '')?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
