<?php

namespace Wonder\Plugin\Ecommerce\Support;

/**
 * @deprecated 0.2.0-beta.1, usare Wonder\Plugin\Gestionale\Support\Throttle. Si toglie nella 0.3.0.
 * Serve il fallback PSR-4: non regge `composer dump-autoload --classmap-authoritative`.
 */
class_alias(\Wonder\Plugin\Gestionale\Support\Throttle::class, \Wonder\Plugin\Ecommerce\Support\Throttle::class);
