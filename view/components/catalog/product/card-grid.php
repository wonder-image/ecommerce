<?php
$product = is_array($product ?? null) ? $product : [];
$url = (string) ($product['url'] ?? '');
$tag = $url !== '' ? 'a' : 'div';
$link = $url !== '' ? ' href="'.e($url).'"' : '';
$variants = array_slice((array) ($product['variants'] ?? []), 0, 5);
?>
<article class="product-card product-card--grid w-100 h-100">
    <<?=$tag?> class="product-card__media p-r d-block f-1-1 bg-light o-hidden"<?=$link?>>
        <?php if (($product['image'] ?? '') !== ''): ?>
            <img src="<?=e($product['image'])?>" alt="<?=e($product['image_alt'] ?? '')?>" class="p-a top start w-100 h-100 bg bg-cover" loading="lazy">
        <?php else: ?>
            <span class="p-a top start w-100 h-100 center tx-secondary" aria-hidden="true"><i class="bi bi-image"></i></span>
        <?php endif; ?>
        <?php if (($product['badge'] ?? '') !== ''): ?>
            <span class="product-card__badge badge badge-primary p-a top start m-2"><?=e($product['badge'])?></span>
        <?php endif; ?>
    </<?=$tag?>>
    <div class="product-card__body pt-2">
        <<?=$tag?> class="product-card__name d-block text fw-500 tx-black max-line-2"<?=$link?>><?=e($product['name'] ?? '')?></<?=$tag?>>
        <div class="product-card__price d-flex gap-2 mt-1 text-small">
            <span class="fw-700"><?=e($product['price'] ?? '')?></span>
            <?php if (($product['compare_at_price'] ?? '') !== ''): ?>
                <s class="tx-secondary"><?=e($product['compare_at_price'])?></s>
            <?php endif; ?>
        </div>
        <?php if ($variants !== []): ?>
            <div class="product-card__variants d-flex gap-1 mt-2" aria-label="Varianti">
                <?php foreach ($variants as $variant): ?>
                    <?php $variantTag = ($variant['url'] ?? '') !== '' ? 'a' : 'span'; ?>
                    <<?=$variantTag?>
                        class="product-card__variant p-r d-block w-15 f-1-1 b-1 b-r o-hidden<?=!empty($variant['active']) ? ' tx-primary' : ' tx-secondary'?>"
                        title="<?=e($variant['name'] ?? '')?>"
                        <?php if ($variantTag === 'a'): ?>href="<?=e($variant['url'])?>"<?php endif; ?>
                    >
                        <?php if (($variant['image'] ?? '') !== ''): ?>
                            <img src="<?=e($variant['image'])?>" alt="<?=e($variant['name'] ?? '')?>" class="p-a top start w-100 h-100 bg bg-cover" loading="lazy">
                        <?php else: ?>
                            <span class="center text-small"><?=e(mb_substr((string) ($variant['name'] ?? ''), 0, 1))?></span>
                        <?php endif; ?>
                    </<?=$variantTag?>>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
