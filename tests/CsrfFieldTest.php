<?php
/** php tests/CsrfFieldTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);

/** @return list<string> percorsi relativi dei file con quelle estensioni */
function csrfFiles(string $root, array $dirs, array $ext): array
{
    $files = [];
    foreach ($dirs as $dir) {
        if (!is_dir($root.'/'.$dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), $ext, true)) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($files);

    return $files;
}

// Restano sul nome storico: login federato, impersonificazione e logout sono del core (Q-CSRFNAME).
$legacy = ['http/backend/impersonation.php', 'view/components/auth/federated.php', 'view/pages/backend/impersonation.php'];

/** Il form di logout del checkout, che va alla route del core. */
function logoutForm(string $root): string
{
    $source = (string) file_get_contents($root.'/view/pages/checkout/index.php');
    $start = strpos($source, '<form id="checkout-logout"');
    if ($start === false) {
        return '';
    }

    return substr($source, $start, (int) strpos($source, '</form>', $start) - $start + 7);
}

check('nessun csrf_token nel codice commerciale, fuori dai file del core', function () use ($root, $legacy) {
    $found = [];
    foreach (csrfFiles($root, ['src', 'http', 'view', 'config', 'resources'], ['php', 'js']) as $file) {
        $source = (string) file_get_contents($root.'/'.$file);
        if ($file === 'view/pages/checkout/index.php') {
            $source = str_replace(logoutForm($root), '', $source);
        }
        if (!in_array($file, $legacy, true) && str_contains($source, 'csrf_token')) {
            $found[] = $file;
        }
    }

    if ($found !== []) {
        throw new RuntimeException(implode(", ", $found));
    }

    return true;
});

check('il logout del checkout manda csrf_token, il nome che il core verifica', function () use ($root) {
    $form = logoutForm($root);

    return str_contains($form, 'ecommerce.auth.logout')
        && str_contains($form, "<?=Csrf::field('csrf_token')?>")
        && !str_contains($form, '<?=Csrf::field()?>');
});

check('nessuna chiamata ad AuthSession per il CSRF nel codice commerciale', function () use ($root, $legacy) {
    foreach (csrfFiles($root, ['src', 'http', 'view'], ['php']) as $file) {
        $source = (string) file_get_contents($root.'/'.$file);
        if (!in_array($file, $legacy, true) && (str_contains($source, 'AuthSession::verify(') || str_contains($source, 'AuthSession::csrfToken('))) {
            throw new RuntimeException($file);
        }
    }

    return true;
});

check('i controller delle mutazioni verificano con Csrf::verify()', function () use ($root) {
    foreach ([
        'src/Frontend/Cart/CartController.php',
        'src/Frontend/Checkout/CheckoutController.php',
        'src/Frontend/Checkout/ExpressController.php',
        'src/Frontend/Subscriptions/SubscriptionsController.php',
    ] as $file) {
        if (!str_contains((string) file_get_contents($root.'/'.$file), 'Csrf::verify()')) {
            throw new RuntimeException($file);
        }
    }

    return true;
});

check('ogni fetch del JS manda l\'header X-WI-CSRF', function () use ($root) {
    foreach (csrfFiles($root, ['resources'], ['js']) as $file) {
        $source = (string) file_get_contents($root.'/'.$file);
        $fetch = substr_count($source, 'fetch(');
        if ($fetch > 0 && substr_count($source, "'X-WI-CSRF': csrfToken(") < $fetch) {
            throw new RuntimeException($file);
        }
    }

    return true;
});

check('il JS legge il token dal meta wi-csrf, poi dal campo _csrf', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
    $express = (string) file_get_contents($root.'/src/Frontend/Checkout/ExpressCheckout.php');

    return str_contains($js, 'meta[name="wi-csrf"]')
        && strpos($js, 'meta[name="wi-csrf"]') < strpos($js, '[name="_csrf"]')
        && str_contains($js, '_csrf: csrfToken(this.config.csrf)')
        && str_contains($express, "'csrf' => Csrf::token()");
});

check('i moduli POST delle viste emettono Csrf::field()', function () use ($root) {
    foreach (['view/components/cart/add.php', 'view/components/checkout/coupon.php', 'view/pages/checkout/index.php', 'view/pages/cart/index.php', 'view/pages/subscriptions/configure.php'] as $file) {
        if (!str_contains((string) file_get_contents($root.'/'.$file), '<?=Csrf::field()?>')) {
            throw new RuntimeException($file);
        }
    }

    return true;
});

check('la ricerca POST del catalogo passa dal guard CSRF del core', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.api.php');
    $search = substr($routes, (int) strpos($routes, "'/catalog/products/search/'"));
    $search = substr($search, 0, (int) strpos($search, ';'));

    return str_contains($search, "->name('catalog.products.search')") && str_contains($search, '->csrf()');
});

summary();
