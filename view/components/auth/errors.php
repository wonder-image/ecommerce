<?php

use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthValidationAlert;

$validationAlert = AuthValidationAlert::make(
    (array) ($errors ?? []),
    $alert ?? null,
    isset($federated_error) ? (string) $federated_error : null,
);
?>
<?php if ($validationAlert !== null): ?>
    <?=$validationAlert->render()?>
<?php endif; ?>
