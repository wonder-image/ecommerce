<?php
/** php tests/integrazione/AccountAddressRememberTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\AccountAddress;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Sql\Transaction;

final class AnnullaAccountAddress extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function provaIndirizzo(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new AnnullaAccountAddress();
        });
    } catch (AnnullaAccountAddress) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

function contattoDiProva(): int
{
    return (int) (Contact::create([
        'name' => 'Ada',
        'surname' => 'Prova',
        'email' => 'indirizzo-'.bin2hex(random_bytes(5)).'@example.com',
    ])->insert_id ?? 0);
}

/** L'ordine come lo scrive il checkout: consegna con i campi `shipping_*`. */
function ordineConsegna(array $cambi = []): array
{
    return $cambi + [
        'fulfillment_type' => 'shipping',
        'shipping_name' => 'Ada',
        'shipping_surname' => 'Prova',
        'shipping_phone_prefix' => '+39',
        'shipping_phone' => '3330001111',
        'shipping_country' => 'IT',
        'shipping_province' => 'MI',
        'shipping_city' => 'Milano',
        'shipping_cap' => '20100',
        'shipping_street' => 'Via Roma',
        'shipping_number' => '1',
        'shipping_more' => '',
    ];
}

/** @return list<array<string, mixed>> */
function indirizziDi(int $contatto): array
{
    $righe = ContactAddress::find(['contact_id' => $contatto, 'deleted' => 'false']);
    $righe = isset($righe['id']) ? [$righe] : array_values((array) $righe);
    usort($righe, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

    return $righe;
}

check('il primo indirizzo si salva e diventa il predefinito', function () {
    $x = provaIndirizzo(static function (): array {
        $contatto = contattoDiProva();
        AccountAddress::remember($contatto, ordineConsegna());

        return indirizziDi($contatto);
    });

    return count($x) === 1
        && $x[0]['street'] === 'Via Roma'
        && $x[0]['city'] === 'Milano'
        && $x[0]['name'] === 'Ada'
        && $x[0]['phone'] === '3330001111'
        && $x[0]['is_default'] === 'true';
});

check('lo stesso indirizzo, anche con maiuscole e spazi diversi, non si ripete', function () {
    $x = provaIndirizzo(static function (): array {
        $contatto = contattoDiProva();
        AccountAddress::remember($contatto, ordineConsegna());
        AccountAddress::remember($contatto, ordineConsegna(['shipping_street' => '  via ROMA ', 'shipping_city' => 'milano']));

        return indirizziDi($contatto);
    });

    return count($x) === 1;
});

check('un indirizzo diverso si aggiunge e il predefinito resta il primo', function () {
    $x = provaIndirizzo(static function (): array {
        $contatto = contattoDiProva();
        AccountAddress::remember($contatto, ordineConsegna());
        AccountAddress::remember($contatto, ordineConsegna(['shipping_street' => 'Corso Como', 'shipping_number' => '9']));

        return indirizziDi($contatto);
    });

    return count($x) === 2
        && $x[0]['is_default'] === 'true'
        && $x[1]['is_default'] === 'false'
        && $x[1]['street'] === 'Corso Como'
        && (int) $x[1]['position'] > (int) $x[0]['position'];
});

check('un indirizzo eliminato non conta: lo stesso torna nell\'account', function () {
    $x = provaIndirizzo(static function (): array {
        $contatto = contattoDiProva();
        AccountAddress::remember($contatto, ordineConsegna());
        // Nel cestino come fa la scheda: `Model::update` non tocca `deleted`.
        sqlModify(ContactAddress::$table, ['deleted' => 'true', 'is_default' => 'false'], 'id', (int) indirizziDi($contatto)[0]['id']);
        AccountAddress::remember($contatto, ordineConsegna());

        return indirizziDi($contatto);
    });

    return count($x) === 1 && $x[0]['is_default'] === 'true';
});

check('il ritiro in negozio e una consegna senza via non salvano niente', function () {
    $x = provaIndirizzo(static function (): array {
        $contatto = contattoDiProva();
        AccountAddress::remember($contatto, ordineConsegna(['fulfillment_type' => 'pickup']));
        AccountAddress::remember($contatto, ordineConsegna(['shipping_street' => ' ']));

        return indirizziDi($contatto);
    });

    return $x === [];
});

check('senza contatto non salva niente', function () {
    return provaIndirizzo(static fn (): ?int => AccountAddress::remember(0, ordineConsegna())) === null;
});

summary();
