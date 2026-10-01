<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('account', ['title' => $title, 'active' => 'overview']);
?>
<div class="d-grid col-1 gap-4">
    <?php foreach ((array) $rows as $row): ?>
        <?=View::component(Ecommerce::viewPath('components/account/row.php'), (array) $row)?>
    <?php endforeach; ?>
</div>
<?php View::end(); ?>
