<?php $cards = is_array($cards ?? null) ? $cards : []; ?>
<div class="product-list product-list--list d-grid gap-4">
    <?php foreach ($cards as $card): ?>
        <?=$card?>
    <?php endforeach; ?>
</div>
