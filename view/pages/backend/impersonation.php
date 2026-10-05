<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Backend\Support\ResourceFormLayoutRenderer as Layout;
use Wonder\Elements\Components\{Button, Card, Container, Text};
use Wonder\View\View;

View::layout('backend.form', [
    'TITLE' => $TITLE,
    'BACK_URL' => $return_url,
]);

$label = trim((string) ($subject->name ?? '').' '.(string) ($subject->surname ?? ''));

if (!($subject->exists ?? false)) {
    $components = ['<div class="col-12"><div class="alert alert-danger mb-0">Cliente non trovato.</div></div>'];
} else {
    ob_start();
    ?>
    <form id="impersonate_user" method="post" action="<?=e(__r('backend.ecommerce.impersonation.issue'))?>" class="col-12">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)->render()?>
        <?=FormField::key('user_id')->hidden()->value((string) $subject_user_id)->render()?>
        <?=FormField::key('continue')->hidden()->value($continue_url)->render()?>
        <?=FormField::key('return')->hidden()->value($return_url)->render()?>
        <?=Button::make('Avvia impersonificazione')->type('submit')->variant('warning')->schema('inline', true)->render()?>
    </form>
    <?php
    $form = (string) ob_get_clean();

    $components = [
        Text::make('Stai per aprire il frontend come <strong>'.e($label !== '' ? $label : (string) ($subject->email ?? '')).'</strong>.')->tag('p')->html()->columnSpan(12),
        Text::make('L’operazione viene registrata e una barra visibile consente di tornare alla tua identità.')->tag('p')->muted()->columnSpan(12),
        $form,
    ];
}

echo Layout::renderLayout((new Container())->columns(12)->components([
    (new Card())->columnSpan(12)->components($components),
]));

View::end();
