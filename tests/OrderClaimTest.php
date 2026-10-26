<?php
/** php tests/OrderClaimTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Auth\OrderClaim;

/**
 * Esegue OrderClaim::run() con collaboratori finti e restituisce le chiamate fatte.
 *
 * @param array<string, mixed> $utente
 * @return array{presi: int, chiamate: list<string>, claim: list<array<int, mixed>>}
 */
function prova(array $utente, bool $verificato = true, int $scheda = 7, bool $entrato = true): array
{
    $traccia = ['chiamate' => [], 'claim' => []];
    OrderClaim::useCollaborators([
        'authenticated' => function () use (&$traccia, $entrato) { $traccia['chiamate'][] = 'authenticated'; return $entrato; },
        'user' => function () use (&$traccia, $utente) { $traccia['chiamate'][] = 'user'; return $utente; },
        'verified' => function (int $id) use (&$traccia, $verificato) { $traccia['chiamate'][] = 'verified'; return $verificato; },
        'customer' => function () use (&$traccia, $scheda) { $traccia['chiamate'][] = 'customer'; return $scheda; },
        'claim' => function (int $id, int $cliente, string $email) use (&$traccia) {
            $traccia['chiamate'][] = 'claim';
            $traccia['claim'][] = [$id, $cliente, $email];

            return 2;
        },
    ]);

    try {
        $presi = OrderClaim::run();
    } finally {
        OrderClaim::useCollaborators(null);
    }

    return ['presi' => $presi] + $traccia;
}

$cliente = ['id' => 42, 'email' => 'ada@example.com', 'deleted' => false, 'area' => ['frontend'], 'authority' => ['client']];

check('cliente con email verificata: claim una volta con id, scheda ed email', function () use ($cliente) {
    $esito = prova($cliente);

    return $esito['presi'] === 2
        && $esito['claim'] === [[42, 7, 'ada@example.com']]
        && $esito['chiamate'] === ['authenticated', 'user', 'verified', 'customer', 'claim'];
});

check('email non verificata: niente claim e la scheda non si tocca', function () use ($cliente) {
    $esito = prova($cliente, false);

    return $esito['presi'] === 0 && $esito['claim'] === [] && !in_array('customer', $esito['chiamate'], true);
});

check('staff (senza ruolo cliente o senza area frontend): mai', function () use ($cliente) {
    $admin = prova(['authority' => ['admin']] + $cliente);
    $backend = prova(['area' => ['backend']] + $cliente);

    return $admin['claim'] === [] && $backend['claim'] === []
        && !in_array('verified', $admin['chiamate'], true);
});

check('utente cancellato: mai', fn () => prova(['deleted' => true] + $cliente)['claim'] === []);

check('senza scheda cliente (customerId 0): niente claim', fn () => prova($cliente, true, 0)['claim'] === []);

check('ospite: si ferma al primo controllo', function () use ($cliente) {
    $esito = prova($cliente, true, 7, false);

    return $esito['presi'] === 0 && $esito['chiamate'] === ['authenticated'];
});

check('nessun segno in sessione: la seconda chiamata riprova', function () use ($cliente) {
    prova($cliente);

    return prova($cliente)['claim'] === [[42, 7, 'ada@example.com']];
});

$root = dirname(__DIR__);
$classe = (string) file_get_contents($root.'/src/Frontend/Auth/OrderClaim.php');

check('i collaboratori veri: CartSession, isUserEmailVerified e OrderOwner::claim del gestionale', fn () =>
    str_contains($classe, "'authenticated' => CartSession::authenticated()")
    && str_contains($classe, "'verified' => \\isUserEmailVerified(")
    && str_contains($classe, "'customer' => CartSession::customerId()")
    && str_contains($classe, "'claim' => OrderOwner::claim(")
    && !str_contains($classe, '$_SESSION'));

$account = (string) file_get_contents($root.'/src/Frontend/Account/EcommerceAccountController.php');
$checkout = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

check('si chiama all\'ingresso dell\'area cliente e del checkout, non da CartSession', function () use ($root, $account, $checkout) {
    $carrello = (string) file_get_contents($root.'/src/Frontend/Cart/CartSession.php');
    $ingresso = static fn (string $sorgente): bool =>
        ($claim = strpos($sorgente, 'OrderClaim::run();')) !== false
        && ($handle = strpos($sorgente, 'function handle(')) !== false
        && $handle < $claim
        && $claim < strpos($sorgente, 'match ($action)', $handle);

    return $ingresso($account) && $ingresso($checkout) && !str_contains($carrello, 'OrderClaim');
});

check('solo sulle pagine che mostrano ordini: non su summary, coupon, place né sulle azioni dell\'account', function () use ($account, $checkout) {
    $filtrato = static fn (string $sorgente): bool =>
        substr_count($sorgente, 'OrderClaim::run();') === 1
        && str_contains($sorgente, "if (in_array(\$action, self::CLAIM_ACTIONS, true)) {\n            OrderClaim::run();");

    return $filtrato($account) && $filtrato($checkout)
        && str_contains($account, "private const CLAIM_ACTIONS = ['index', 'orders', 'orders.show'];")
        && str_contains($checkout, "private const CLAIM_ACTIONS = ['index', 'completed', 'return'];")
        && str_contains($checkout, "'completed' => self::completed(),")
        && str_contains($checkout, "'return' => self::returned(),")
        && str_contains($account, "'orders.show' => \$this->order(");
});

summary();
