<?php

use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthController;

AuthController::handle(
    (string) (($ROUTE_META['auth_action'] ?? null) ?: ''),
    (array) ($ROUTE_PARAMETERS ?? [])
);
