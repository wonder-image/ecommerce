<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ImageFormat;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\View\View;

$product = is_array($product ?? null) ? $product : [];
$url = (string) ($product['url'] ?? '');
$tag = $url !== '' ? 'a' : 'div';
$link = $url !== '' ? ' href="'.e($url).'"' : '';
// Una sola variante non è una scelta: la fila compare da due in su.
$variants = (array) ($product['variants'] ?? []);
$variants = count($variants) > 1 ? $variants : [];
// Al massimo cinque, e quella della card resta sempre in fila.
$active = array_values(array_filter($variants, static fn (mixed $variant): bool => !empty($variant['active'])));
$variants = array_slice($variants, 0, 5);
if ($active !== [] && !in_array($active[0], $variants, true)) $variants[4] = $active[0];
$cart = is_array($product['cart'] ?? null) ? $product['cart'] : null;
$description = Ecommerce::config('catalog.card.short_description', false) === true ? (string) ($product['description'] ?? '') : '';
?>
<article class="product-card product-card--grid w-100 h-100">
    <<?=$tag?> class="product-card__media p-r d-block <?=ImageFormat::className('catalog.card.image_format', '1:1')?> bg-light o-hidden"<?=$link?>>
        <?php if (($product['image'] ?? '') !== ''): ?>
            <?=ImageFormat::fit(Product::image((string) $product['image'], Product::IMAGE_CARD, (string) ($product['image_alt'] ?? '')), 'catalog.card.image_fit')?>
        <?php else: ?>
            <span class="center tx-secondary" aria-hidden="true"><i class="bi bi-image"></i></span>
        <?php endif; ?>
        <?php if (($product['badge'] ?? '') !== ''): ?>
            <span class="product-card__badge badge badge-primary p-a top start m-2"><?=e($product['badge'])?></span>
        <?php endif; ?>
    </<?=$tag?>>
    <div class="product-card__body w-100 pt-2">
        <<?=$tag?> class="product-card__name d-block text fw-500 tx-black tx-none max-line-2"<?=$link?>><?=e($product['name'] ?? '')?></<?=$tag?>>
        <?php if ($description !== ''): ?>
            <p class="product-card__description text-small tx-dark mt-1 max-line-2"><?=e($description)?></p>
        <?php endif; ?>
        <div class="product-card__price d-flex gap-2 mt-1 text-small">
            <span class="fw-700"><?=e($product['price'] ?? '')?></span>
            <?php if (($product['compare_at_price'] ?? '') !== ''): ?>
                <s class="fw-400 tx-dark"><?=e($product['compare_at_price'])?></s>
            <?php endif; ?>
        </div>
        <?php if ($variants !== []): ?>
            <div class="product-card__variants d-flex flex-wrap gap-1 mt-2" aria-label="<?=e(__t('ecommerce.catalog.variants'))?>">
                <?php foreach ($variants as $variant): ?>
                    <?php $variantTag = ($variant['url'] ?? '') !== '' ? 'a' : 'span'; ?>
                    <<?=$variantTag?>
                        class="product-card__variant d-block p-r b-1 o-hidden tx-dark tx-none"
                        title="<?=e($variant['name'] ?? '')?>"
                        <?php if ($variantTag === 'a'): ?>href="<?=e($variant['url'])?>"<?php endif; ?>
                        <?php if (!empty($variant['active'])): ?>aria-current="true"<?php endif; ?>
                    >
                        <?php if (($variant['image'] ?? '') !== ''): ?>
                            <?=ImageFormat::fit(Product::image((string) $variant['image'], Product::IMAGE_THUMB, (string) ($variant['name'] ?? ''))->displaySizes('32px'), 'catalog.card.variants_fit')?>
                        <?php else: ?>
                            <span class="d-block text-xsmall p-1"><?=e($variant['name'] ?? '')?></span>
                        <?php endif; ?>
                    </<?=$variantTag?>>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($cart !== null): ?>
            <div class="product-card__cart w-100 mt-3">
                <?php if ($cart['product_id'] > 0 && $cart['available']): ?>
                    <?=View::component(Ecommerce::viewPath('components/cart/add.php'), [
                        'product_id' => $cart['product_id'],
                        'stepper' => $cart['quantity'],
                        'id' => '',
                    ])?>
                <?php elseif ($cart['product_id'] > 0): ?>
                    <button class="btn btn-primary w-100" type="button" disabled><?=e(__t('ecommerce.catalog.availability.out_of_stock'))?></button>
                <?php elseif ($url !== ''): ?>
                    <a class="btn btn-black-o w-100" href="<?=e($url)?>"><?=e(__t('ecommerce.catalog.choose_options'))?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
