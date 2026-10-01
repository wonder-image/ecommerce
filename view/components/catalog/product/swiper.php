<?php
use Wonder\Elements\Media\Swiper;

$cards = is_array($cards ?? null) ? $cards : [];
$options = is_array($swiper ?? null) ? $swiper : [];

$component = Swiper::make()
    ->slides($cards)
    ->slidesPerView((float) ($options['slides_per_view'] ?? 1.2))
    ->spaceBetween((int) ($options['space_between'] ?? 12))
    ->breakpoints((array) ($options['breakpoints'] ?? []))
    ->navigation((bool) ($options['navigation'] ?? true))
    ->pagination((bool) ($options['pagination'] ?? false))
    ->watchOverflow()
    ->class('product-list product-list--swiper');
?>
<?=$component->render()?>
