<?php
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

$selection = is_array($selection ?? null) ? $selection : [];
$product = $selection['products'][0] ?? [];
// La foto della selezione; senza, quella dell'articolo.
$image = ($selection['image'] ?? '') !== '' ? $selection['image'] : (string) ($product['image'] ?? '');
$right = ($selection['meta']['image_side'] ?? '') === 'right';
$background = (string) ($selection['background'] ?? '');
$title = ($selection['title'] ?? '') !== '' ? $selection['title'] : null;
$subtitle = ($selection['subtitle'] ?? '') !== '' ? $selection['subtitle'] : null;
$text = ($selection['text'] ?? '') !== '' ? $selection['text'] : null;
?>
<?=View::component(Ecommerce::viewPath('components/catalog/product/header.php'), [
    'title' => $title,
    'subtitle' => $subtitle,
    'text' => $text,
])?>
<div class="selection-spotlight w-100 d-grid col-2 col-p-1 gap-5">
    <div class="selection-spotlight__media p-r f-1-1 o-hidden bg-light"<?=$right ? ' style="order: 2"' : ''?>>
        <?php if ($image !== ''): ?>
            <img class="p-a top start w-100 h-100" style="object-fit: cover" src="<?=e($image)?>" alt="<?=e($product['name'] ?? '')?>" loading="lazy">
        <?php endif; ?>
    </div>
    <div class="selection-spotlight__product p-r d-flex o-hidden p-5" style="align-items: center; justify-content: center<?=$background !== '' ? "; background: url('".e($background)."') center / cover no-repeat" : ''?>">
        <div class="w-100" style="max-width: 320px"><?=$card?></div>
    </div>
</div>
