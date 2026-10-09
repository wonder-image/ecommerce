<?php
/** php tests/integrazione/OrderEmailExtrasTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/compra.php';
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/FakePaymentProvider.php';

use Wonder\Sql\Transaction;
use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

/** Un cliente del negozio senza password, come lo crea il checkout dell'ospite. */
function ospite(string $email): int
{
    return (int) (User::create([
        'name' => 'Ida',
        'surname' => 'Cliente',
        'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ])->insert_id ?? 0);
}

/** I token per scegliere la password dell'utente: il link del checkout ne crea uno. */
function tokenRipristino(int $utente): int
{
    $query = sqlSelect('auth_one_time_tokens', [
        'purpose' => 'password_reset',
        'subject_user_id' => $utente,
    ]);

    if (!($query->exists ?? false)) {
        return 0;
    }

    return isset($query->row['id']) ? 1 : count((array) $query->row);
}

check('l\'ecommerce dà dati alle email dell\'ordine', fn () => is_subclass_of(Ecommerce::class, ProvidesOrderEmailExtras::class));

check('la conferma dell\'ospite senza password porta il link per sceglierla', fn () => prova(static function (): bool {
    $utente = ospite('ospite-conferma-'.bin2hex(random_bytes(4)).'@example.test');
    $dati = Ecommerce::orderEmailExtras('confirmed', ['id' => 1, 'user_id' => $utente]);

    return $utente > 0
        && str_contains((string) ($dati['account_url'] ?? ''), '?token=')
        && Ecommerce::orderEmailExtras('shipped', ['id' => 1, 'user_id' => $utente]) === [];
}));

check('l\'email «ricevuto» non crea un secondo link: quello del checkout c\'è già', fn () => prova(static function (): bool {
    $utente = ospite('ospite-ricevuto-'.bin2hex(random_bytes(4)).'@example.test');
    GuestCheckout::passwordLink($utente, '/account/password-restore/');
    $prima = tokenRipristino($utente);

    return $utente > 0
        && $prima === 1
        && Ecommerce::orderEmailExtras('received', ['id' => 1, 'user_id' => $utente]) === []
        && tokenRipristino($utente) === $prima;
}));

check('senza account nell\'ordine non c\'è link', fn () =>
    Ecommerce::orderEmailExtras('confirmed', ['id' => 1, 'user_id' => 0]) === []
    && Ecommerce::orderEmailExtras('confirmed', ['id' => 1]) === []);

summary();
