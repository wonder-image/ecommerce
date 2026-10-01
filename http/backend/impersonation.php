<?php

use Wonder\Auth\Impersonation;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Support\SafeRedirect;
use Wonder\View\View;

$service = new Impersonation(
    (array) Ecommerce::config('impersonation.actor_authorities', ['admin']),
    (int) Ecommerce::config('impersonation.token_ttl', 120),
);

$subjectUserId = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$subject = infoUser($subjectUserId, 'id');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    View::make(Ecommerce::viewPath('pages/backend/impersonation.php'), [
        'TITLE' => 'Impersonifica cliente',
        'subject' => $subject,
        'subject_user_id' => $subjectUserId,
        'csrf_token' => $service->csrfToken('issue'),
        'continue_url' => SafeRedirect::fromRequest($_GET['continue'] ?? '', '/account/'),
        'return_url' => SafeRedirect::fromRequest($_GET['return'] ?? '', '/backend/'),
    ])->render();
    return;
}

if (!$service->verifyCsrf((string) ($_POST['csrf_token'] ?? ''), 'issue')) {
    http_response_code(419);
    exit('CSRF token invalid');
}

$issued = $service->issue(
    (int) ($_SESSION['user_id'] ?? 0),
    $subjectUserId,
    SafeRedirect::fromRequest($_POST['continue'] ?? '', '/account/'),
    SafeRedirect::fromRequest($_POST['return'] ?? '', '/backend/'),
    __r('ecommerce.auth.impersonation.stop'),
);

header('Location: '.__r('ecommerce.auth.impersonation.start').'?token='.rawurlencode($issued->token));
exit;
