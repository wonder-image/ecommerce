<?php

use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressController;

ExpressController::handle(
    (string) (($ROUTE_META['express_action'] ?? null) ?: '')
);
