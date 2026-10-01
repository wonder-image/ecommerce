<?php

use Wonder\View\View;
use Wonder\Plugin\Ecommerce\Ecommerce;

$title ??= '';
$text ??= '';
$authAlert = View::component(Ecommerce::viewPath('components/auth/errors.php'), [
    'alert' => $alert ?? null,
    'errors' => (array) ($errors ?? []),
    'federated_error' => $federated_error ?? null,
]);
View::layout('frontend.minimal');
?>
<main class="full-page" style="background:var(--auth-bg-color,var(--bg-color));color:var(--auth-tx-color,var(--tx-color));">
    <?=$authAlert?>
    <section>
        <div class="content content-little">
            <?php if ($title !== ''): ?><h1 class="subtitle"><?=e($title)?></h1><?php endif; ?>
            <?php if ($text !== ''): ?><p class="text-small mt-3"><?=e($text)?></p><?php endif; ?>
            <?=$PAGE_CONTENT?>
        </div>
    </section>
</main>
<?php View::end(); ?>
