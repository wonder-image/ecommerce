<?php
$account_panel ??= new \Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountPanel();
$user ??= \infoUser((int) ($_SESSION['user_id'] ?? 0), 'id');
$account_panel->layout([
    'title' => $title ?? '', 'active' => $active ?? 'overview',
    'notice' => $notice ?? '', 'errors' => $errors ?? [],
    'user' => $user, 'csrf_token' => $csrf_token,
    'logout_url' => $logout_url ?? __r('ecommerce.auth.logout'),
    'page_modals' => $page_modals ?? [],
]);
echo \Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('account');
echo \Wonder\Plugin\Ecommerce\Frontend\StoreStyle::sheet();
echo $PAGE_CONTENT;
\Wonder\View\View::end();
