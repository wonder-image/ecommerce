<?php

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ImageFormat;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;

/*
 * La foto di una riga: carrello, mini carrello, checkout e ordine dell'area
 * cliente. Formato e fit sono quelli della card (`catalog.card.image_*`) e la
 * larghezza è sempre la stessa, così l'articolo si riconosce da un passo all'altro.
 *
 * $image    il file della foto ('' = riquadro vuoto, 'data:,' = segnaposto del template JS)
 * $url      se c'è, la foto porta alla scheda (fuori dal tab: il link vero è il nome)
 * $quantity se c'è, il badge della quantità nell'angolo
 */

$size = 72;
$fit = ImageFormat::cover('catalog.card.image_fit') ? 'cover' : 'contain';
$image = trim((string) ($image ?? ''));
$url = trim((string) ($url ?? ''));
$tag = $url !== '' ? 'a' : 'span';
$attributes = $url !== '' ? ' href="'.e($url).'" tabindex="-1" aria-hidden="true"' : '';
?>
<<?=$tag.$attributes?> class="wi-thumb" style="--wi-thumb-size: <?=$size?>px; aspect-ratio: <?=ImageFormat::aspectRatio('catalog.card.image_format', '1:1')?>">
    <?php if ($image === 'data:,'): ?>
        <?php // Segnaposto del template JS: checkout.js lo sostituisce con la foto fatta da `__ri()`. ?>
        <img src="data:," alt="" class="skeleton bg bg-<?=$fit?>" data-line-image>
    <?php elseif ($image !== ''): ?>
        <?=ImageFormat::fit(Product::image($image, Product::IMAGE_THUMB)->displaySizes($size.'px'), 'catalog.card.image_fit')?>
    <?php endif; ?>
    <?php if (isset($quantity)): ?><span class="badge badge-dark" data-line-quantity><?=e((string) $quantity)?></span><?php endif; ?>
</<?=$tag?>>
