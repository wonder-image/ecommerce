<?php

use Wonder\Plugin\Ecommerce\Frontend\Account\AccountController;

AccountController::handle(
    (string) (($ROUTE_META['account_action'] ?? null) ?: ''),
    (array) ($ROUTE_PARAMETERS ?? [])
);
