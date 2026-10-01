<?php if (($title ?? null) !== null || ($subtitle ?? null) !== null): ?>
    <header class="product-list__header mb-6">
        <?php if (($title ?? null) !== null): ?>
            <h2 class="title tx-primary"><?=e($title)?></h2>
        <?php endif; ?>
        <?php if (($subtitle ?? null) !== null): ?>
            <p class="text tx-secondary mt-2"><?=e($subtitle)?></p>
        <?php endif; ?>
    </header>
<?php endif; ?>
