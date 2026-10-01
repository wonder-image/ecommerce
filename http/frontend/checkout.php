<?php

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutController;

CheckoutController::handle(
    (string) (($ROUTE_META['checkout_action'] ?? null) ?: '')
);
