<?php
$product = is_array($product ?? null) ? $product : [];
$url = (string) ($product['url'] ?? '');
$tag = $url !== '' ? 'a' : 'div';
$link = $url !== '' ? ' href="'.e($url).'"' : '';
?>
<article class="product-card product-card--list d-grid col-4 col-p-1 gap-4 pb-4">
    <<?=$tag?> class="product-card__media p-r d-block f-1-1 bg-light o-hidden"<?=$link?>>
        <?php if (($product['image'] ?? '') !== ''): ?>
            <img src="<?=e($product['image'])?>" alt="<?=e($product['image_alt'] ?? '')?>" class="p-a top start w-100 h-100 bg bg-cover" loading="lazy">
        <?php else: ?>
            <span class="p-a top start w-100 h-100 center tx-secondary" aria-hidden="true"><i class="bi bi-image"></i></span>
        <?php endif; ?>
    </<?=$tag?>>
    <div class="product-card__body col-3 col-p-1 d-flex d-column j-content-center">
        <?php if (($product['badge'] ?? '') !== ''): ?>
            <div><span class="badge badge-primary"><?=e($product['badge'])?></span></div>
        <?php endif; ?>
        <<?=$tag?> class="product-card__name d-block text fw-600 tx-black mt-2"<?=$link?>><?=e($product['name'] ?? '')?></<?=$tag?>>
        <?php if (($product['description'] ?? '') !== ''): ?>
            <p class="text-small tx-secondary mt-2 max-line-2"><?=e($product['description'])?></p>
        <?php endif; ?>
        <div class="product-card__price d-flex gap-2 mt-2 text-small">
            <span class="fw-700"><?=e($product['price'] ?? '')?></span>
            <?php if (($product['compare_at_price'] ?? '') !== ''): ?>
                <s class="tx-secondary"><?=e($product['compare_at_price'])?></s>
            <?php endif; ?>
        </div>
    </div>
</article>
