<?php

use Wonder\Elements\Components\Button;

$label = trim((string) ($label ?? ''));
$value = array_values(array_filter(array_map('strval', (array) ($value ?? []))));
$href = (string) ($href ?? '#');
$action = trim((string) ($action ?? ''));
?>
<article class="wi-box d-grid col-3 col-p-1 gap-4 p-4">
    <div class="col-2 col-p-1">
        <h2 class="subtitle mb-2"><?=e($label)?></h2>
        <?php foreach ($value as $line): ?>
            <p class="text-small"><?=e($line)?></p>
        <?php endforeach; ?>
    </div>
    <div class="d-flex f-end f-p-start">
        <?=Button::to($href, $action)->variant('primary')->outline()->size('sm')->arrow()->render()?>
    </div>
</article>
