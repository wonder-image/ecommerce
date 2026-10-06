<?php $cards = is_array($cards ?? null) ? $cards : []; ?>
<div class="product-list product-list--grid w-100 <?=e($grid_class ?? '')?>">
    <?php foreach ($cards as $card): ?>
        <?=$card?>
    <?php endforeach; ?>
</div>
