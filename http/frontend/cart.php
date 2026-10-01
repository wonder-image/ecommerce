<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartController;

CartController::handle(
    (string) (($ROUTE_META['cart_action'] ?? null) ?: ''),
    (array) ($ROUTE_PARAMETERS ?? [])
);
