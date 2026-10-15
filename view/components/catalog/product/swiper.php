<?php
use Wonder\Elements\Media\Swiper;

$cards = is_array($cards ?? null) ? $cards : [];
$options = is_array($swiper ?? null) ? $swiper : [];
$navigation = ($navigation ?? null) !== null ? (string) $navigation : null;

// Con le frecce nell'intestazione lo swiper non disegna le sue.
$component = Swiper::make()
    ->slides($cards)
    ->slidesPerView((float) ($options['slides_per_view'] ?? 1.2))
    ->spaceBetween((int) ($options['space_between'] ?? 12))
    ->breakpoints((array) ($options['breakpoints'] ?? []))
    ->navigation($navigation === null && (bool) ($options['navigation'] ?? true))
    ->pagination((bool) ($options['pagination'] ?? false))
    ->watchOverflow()
    ->class('product-list product-list--swiper');
?>
<?php if ($navigation === null): ?>
<?=$component->render()?>
<?php else: ?>
<div class="w-100" style="min-width: 0" data-product-list-swiper="<?=e($navigation)?>"><?=$component->render()?></div>
<script>
window.addEventListener('loaded', function () {
    var id = <?=json_encode($navigation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>;
    var nav = document.querySelector('[data-product-list-nav="' + id + '"]');
    var root = document.querySelector('[data-product-list-swiper="' + id + '"] .swiper');
    var swiper = root && root.swiper;
    if (!nav || !swiper) { return; }
    var prev = nav.querySelector('[data-product-list-prev]');
    var next = nav.querySelector('[data-product-list-next]');
    prev.addEventListener('click', function () { swiper.slidePrev(); });
    next.addEventListener('click', function () { swiper.slideNext(); });
    // Ai capi i bottoni si spengono; se le card stanno tutte, spariscono.
    var sync = function () {
        prev.disabled = swiper.isBeginning;
        next.disabled = swiper.isEnd;
        nav.classList.toggle('d-none', swiper.isLocked);
    };
    swiper.on('slideChange reachBeginning reachEnd fromEdge lock unlock resize', sync);
    sync();
});
</script>
<?php endif; ?>
