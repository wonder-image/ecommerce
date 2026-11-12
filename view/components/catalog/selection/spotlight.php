<?php
use Wonder\App\Media\MediaFile;
use Wonder\Elements\Media\Image;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

$selection = is_array($selection ?? null) ? $selection : [];
$product = $selection['products'][0] ?? [];
$name = (string) ($product['name'] ?? '');

// La foto della selezione dal suo media; una riga non migrata ha ancora
// l'indirizzo del file di prima; senza nessuno dei due, la foto dell'articolo.
$media = $selection['image_media'] ?? null;
$photo = $media instanceof MediaFile && $media->image() !== null ? Image::fromMedia($media) : null;
if ($photo !== null) {
    // L'alt del media; vuoto, il nome dell'articolo. Skeleton e lazy come __ri().
    $photo->skeleton()->notDraggable()->loading()->attr('alt', $media->alt !== '' ? $media->alt : $name);
} else {
    $src = $media instanceof MediaFile ? '' : (string) ($selection['image'] ?? '');
    $src = $src !== '' ? $src : (string) ($product['image'] ?? '');
    $photo = $src !== '' ? __ri($src)->attr('alt', $name) : null;
}

// Lo sfondo è decorativo: alt vuoto.
$media = $selection['background_media'] ?? null;
$background = $media instanceof MediaFile ? $media->image()?->skeleton()->notDraggable()->loading() : null;
if ($background === null && !$media instanceof MediaFile && ($selection['background'] ?? '') !== '') {
    $background = __ri((string) $selection['background']);
}

$right = ($selection['meta']['image_side'] ?? '') === 'right';
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
        <?php if ($photo !== null): ?>
            <?=$photo->size(960)->displaySizes('(max-width: 768px) 100vw, 50vw')->fitCover()?>
        <?php endif; ?>
    </div>
    <div class="selection-spotlight__product p-r d-flex o-hidden p-5" style="align-items: center; justify-content: center">
        <?php if ($background !== null): ?>
            <?=$background->size(1440)->displaySizes('(max-width: 768px) 100vw, 50vw')->fitCover()->attr('alt', '')?>
        <?php endif; ?>
        <div class="p-r w-100" style="max-width: 320px"><?=$card?></div>
    </div>
</div>
