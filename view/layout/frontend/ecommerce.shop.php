<?php

use Wonder\View\View;

$overlay = (string) ($overlay ?? '');

View::layout('frontend.main');
?>
<main>
    <section class="intro">
        <div class="content">
            <div class="w-100"><?=$PAGE_CONTENT?></div>
        </div>
    </section>
</main>
<?=$overlay?>
<?php View::end(); ?>
