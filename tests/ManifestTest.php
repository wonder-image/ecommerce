<?php
/** php tests/ManifestTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\LegacyGlobals;
use Wonder\App\Module\Manifest;
use Wonder\App\Module\ManifestValidator;

LegacyGlobals::share(['ROOT' => dirname(__DIR__)]);

$manifest = Manifest::fromFile(dirname(__DIR__).'/module.json', 'local');

check('manifest valido per il core', function () use ($manifest) {
    $errors = ManifestValidator::errors($manifest);

    if ($errors !== []) {
        echo '    '.implode("\n    ", $errors)."\n";
    }

    return $errors === [];
});

check('slug, namespace ed entrypoint', fn () =>
    $manifest->slug() === 'ecommerce'
    && $manifest->namespace() === 'Wonder\\Plugin\\Ecommerce\\'
    && $manifest->entrypoint() === 'Wonder\\Plugin\\Ecommerce\\Ecommerce'
);

check('richiede il core 2.4.0-beta.1 e PHP 8.2', fn () =>
    ($manifest->frameworkCompatibility()['wonder-app'] ?? '') === '^2.4.0-beta.1'
    && ($manifest->frameworkCompatibility()['php'] ?? '') === '^8.2'
);

// Il negozio legge i dati del gestionale: senza quel modulo non ha senso, e il
// core pretende che la dipendenza sia abilitata per nome (Registry::enabled()).
check('dipende dal modulo gestionale', fn () =>
    $manifest->dependencySlugs() === ['gestionale']
);

check('dichiara frontend, backend di impersonificazione e API di ricerca', fn () =>
    $manifest->routeFile('frontend') === dirname(__DIR__).'/config/routes/route.frontend.php'
    && is_file((string) $manifest->routeFile('frontend'))
    && $manifest->routeFile('backend') === dirname(__DIR__).'/config/routes/route.backend.php'
    && is_file((string) $manifest->routeFile('backend'))
    && $manifest->routeFile('api') === dirname(__DIR__).'/config/routes/route.api.php'
    && is_file((string) $manifest->routeFile('api'))
);

check('le rotte backend del modulo ereditano il gruppo backend del core', function () use ($manifest) {
    $source = file_get_contents((string) $manifest->routeFile('backend'));

    return is_string($source)
        && str_contains($source, "Route::name('ecommerce.')")
        && !str_contains($source, "prefix('/backend')")
        && !str_contains($source, "area('backend')");
});

// Il backend e le tabelle sono del gestionale: il negozio non aggiunge pagine
// al pannello, tabelle sue o comandi forge (4.1 della spec di architettura).
check('non dichiara database né comandi', fn () =>
    $manifest->get('database') === null
    && $manifest->consoleCommands() === []
);

check('il file dei permessi esiste', fn () =>
    is_file((string) $manifest->permissionsFile())
    && (require (string) $manifest->permissionsFile()) instanceof \Wonder\App\Permission\PermissionRegistry
);

summary();
