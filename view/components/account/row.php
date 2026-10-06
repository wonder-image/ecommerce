<?php
echo \Wonder\View\View::component('frontend.account.row', [
    'label' => $label ?? '', 'value' => $value ?? [],
    'href' => $href ?? '', 'action' => $action ?? '',
    'action_component' => $action_component ?? null,
]);
