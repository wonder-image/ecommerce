<?php

use Wonder\Plugin\Gestionale\Models\Catalog\Product;

/*
 * La foto di una riga: carrello, mini carrello, checkout e ordine dell'area
 * cliente. Sempre quadrata e sempre dello stesso lato, così l'articolo si
 * riconosce da un passo all'altro; la foto sta dentro intera (fitContain).
 *
 * $image    il file della foto ('' = riquadro vuoto, 'data:,' = segnaposto del template JS)
 * $url      se c'è, la foto porta alla scheda (fuori dal tab: il link vero è il nome)
 * $quantity se c'è, il badge della quantità nell'angolo
 */

$size = 72;
$image = trim((string) ($image ?? ''));
$url = trim((string) ($url ?? ''));
$tag = $url !== '' ? 'a' : 'span';
$attributes = $url !== '' ? ' href="'.e($url).'" tabindex="-1" aria-hidden="true"' : '';
?>
<<?=$tag.$attributes?> class="wi-thumb" style="--wi-thumb-size: <?=$size?>px">
    <?php if ($image === 'data:,'): ?>
        <?php // Il template del JS ha un <img> semplice: checkout.js ne cambia solo il src. ?>
        <img src="data:," alt="" class="skeleton bg bg-contain" data-line-image>
    <?php elseif ($image !== ''): ?>
        <?=Product::image($image, Product::IMAGE_THUMB)->displaySizes($size.'px')?>
    <?php endif; ?>
    <?php if (isset($quantity)): ?><span class="badge badge-dark" data-line-quantity><?=e((string) $quantity)?></span><?php endif; ?>
</<?=$tag?>>
