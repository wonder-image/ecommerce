<?php

use Wonder\View\View;

$title ??= '';
$active ??= 'company';
$companies = array_values(array_filter((array) ($companies ?? []), 'is_array'));
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<div class="d-flex d-column gap-6">
    <?php foreach ($companies as $company): ?>
        <section class="d-flex d-column gap-3" aria-labelledby="azienda-<?=e((string) $company['id'])?>">
            <h2 class="subtitle" id="azienda-<?=e((string) $company['id'])?>"><?=e($company['name'])?></h2>
            <?=View::component('frontend.account.row', $company['billing'])?>
            <h3 class="text fw-500"><?=e((string) __t('ecommerce.account.company.people'))?></h3>
            <?php foreach ($company['people'] as $row): ?>
                <?=View::component('frontend.account.row', $row)?>
            <?php endforeach; ?>
            <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.company.people_note'))?></p>
        </section>
    <?php endforeach; ?>
</div>
<?php View::end(); ?>
