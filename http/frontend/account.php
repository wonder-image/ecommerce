<?php

use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountController;

(new EcommerceAccountController(AccountRoutes::panel(), AccountRoutes::auth()))
    ->handle((string) ($ROUTE_META['account_action'] ?? ''), (array) ($ROUTE_PARAMETERS ?? []));
