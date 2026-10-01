<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\View\View;

View::layout('backend.form', [
    'TITLE' => $TITLE,
    'BACK_URL' => $return_url,
]);

$label = trim((string) ($subject->name ?? '').' '.(string) ($subject->surname ?? ''));
?>
<wi-card>
    <?php if (!($subject->exists ?? false)): ?>
        <div class="alert alert-danger mb-0">Cliente non trovato.</div>
    <?php else: ?>
        <p>Stai per aprire il frontend come <strong><?=e($label !== '' ? $label : (string) ($subject->email ?? ''))?></strong>.</p>
        <p class="text-body-secondary">L’operazione viene registrata e una barra visibile consente di tornare alla tua identità.</p>
        <form id="impersonate_user" method="post" action="<?=e(__r('backend.ecommerce.impersonation.issue'))?>">
            <?=FormField::key('csrf_token')->hidden()->value($csrf_token)->render()?>
            <?=FormField::key('user_id')->hidden()->value((string) $subject_user_id)->render()?>
            <?=FormField::key('continue')->hidden()->value($continue_url)->render()?>
            <?=FormField::key('return')->hidden()->value($return_url)->render()?>
            <button type="submit" class="btn btn-warning">Avvia impersonificazione</button>
        </form>
    <?php endif; ?>
</wi-card>
<?php View::end(); ?>
