<?php
use Wonder\Plugin\Ecommerce\Frontend\Slots;
use Wonder\View\View;
// Lo apre EcommerceAccountPanel::layout(): `panel_layout` sono i dati per il layout del core.
$panel_layout = (array) ($panel_layout ?? []);
View::layout('frontend.account.panel', $panel_layout);
$slot = ['section' => (string) ($panel_layout['active'] ?? $active ?? ''), 'user' => $panel_layout['user'] ?? $user ?? null];
echo Slots::render('account.before', $slot);
echo $PAGE_CONTENT;
echo Slots::render('account.after', $slot);
View::end();
