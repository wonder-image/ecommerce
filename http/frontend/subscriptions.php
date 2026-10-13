<?php

use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;

SubscriptionsController::handle(
    (string) (($ROUTE_META['subscriptions_action'] ?? null) ?: ''),
    (array) ($ROUTE_PARAMETERS ?? [])
);
