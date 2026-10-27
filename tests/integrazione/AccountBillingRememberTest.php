<?php
/** php tests/integrazione/AccountBillingRememberTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$_SERVER['DOCUMENT_ROOT'] = SITE;
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\AccountAddress;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutController;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;
use Wonder\Sql\Transaction;

final class AnnullaFatturazione extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function provaFatturazione(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new AnnullaFatturazione();
        });
    } catch (AnnullaFatturazione) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

/** Un cliente entrato con la sua scheda: [utente, scheda]. */
function clienteFatturazione(): array
{
    $email = 'fatturazione-'.bin2hex(random_bytes(6)).'@example.com';
    $userId = (int) (User::create([
        'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ])->insert_id ?? 0);
    markUserEmailVerified($userId, date('Y-m-d H:i:s'));
    $GLOBALS['ALERT'] = null;
    $scheda = (int) ContactAccount::link($userId)->contact_id;
    Contact::update(['name' => 'Ada', 'surname' => 'Lovelace', 'phone_prefix' => '+39', 'phone' => '3330001111'], $scheda);

    return [$userId, $scheda];
}

/** Il carrello come lo scrive il checkout: consegna a Milano. */
function ordineFatturazione(array $cambi = []): array
{
    return $cambi + [
        'fulfillment_type' => 'shipping',
        'shipping_name' => 'Bruno', 'shipping_surname' => 'Regalato',
        'shipping_phone_prefix' => '+39', 'shipping_phone' => '3339998888',
        'shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano',
        'shipping_cap' => '20100', 'shipping_street' => 'Via Roma', 'shipping_number' => '1', 'shipping_more' => '',
    ];
}

/** La fatturazione scritta a mano, diversa dalla consegna, con la fattura. */
function postFattura(array $cambi = []): array
{
    return $cambi + [
        'same_as_shipping' => '0', 'invoice' => '1',
        'billing_type' => 'private', 'billing_name' => 'Ada', 'billing_surname' => 'Lovelace',
        'billing_country' => 'IT', 'billing_province' => 'TO', 'billing_city' => 'Torino',
        'billing_cap' => '10100', 'billing_street' => 'Via Verdi', 'billing_number' => '2', 'billing_more' => '',
        'billing_cf' => 'LVLDAA80A41L219X',
    ];
}

function salva(int $scheda, array $post, array $ordine): bool
{
    return AccountAddress::rememberBilling($scheda, CheckoutRules::billing($post, $ordine), $post);
}

/** I valori del modulo del checkout per il cliente entrato e il carrello dato. */
function valoriPagina(int $userId, array $ordine): array
{
    $_SESSION['user_id'] = $userId;
    $metodo = new ReflectionMethod(CheckoutController::class, 'pageValues');

    return $metodo->invoke(null, ['order' => $ordine], []);
}

check('la fatturazione con fattura entra nella scheda del cliente', fn () => provaFatturazione(static function (): bool {
    [, $scheda] = clienteFatturazione();
    $ok = salva($scheda, postFattura(), ordineFatturazione());
    $c = (array) Contact::findById($scheda);

    return $ok && $c['street'] === 'Via Verdi' && $c['city'] === 'Torino' && $c['province'] === 'TO'
        && $c['cf'] === 'LVLDAA80A41L219X' && $c['type'] === 'private'
        && $c['name'] === 'Ada' && $c['surname'] === 'Lovelace';
}));

check('senza fattura i dati fiscali salvati restano, l\'indirizzo si aggiorna', fn () => provaFatturazione(static function (): bool {
    [, $scheda] = clienteFatturazione();
    salva($scheda, postFattura(), ordineFatturazione());
    $ok = salva($scheda, postFattura(['invoice' => '', 'billing_street' => 'Via Po', 'billing_number' => '3']), ordineFatturazione());
    $c = (array) Contact::findById($scheda);

    return $ok && $c['street'] === 'Via Po' && $c['cf'] === 'LVLDAA80A41L219X';
}));

check('con «uguale alla spedizione» si salva l\'indirizzo, non il nome di chi riceve', fn () => provaFatturazione(static function (): bool {
    [, $scheda] = clienteFatturazione();
    $ok = salva($scheda, ['same_as_shipping' => '1'], ordineFatturazione());
    $c = (array) Contact::findById($scheda);

    return $ok && $c['street'] === 'Via Roma' && $c['city'] === 'Milano'
        && $c['name'] === 'Ada' && $c['surname'] === 'Lovelace' && $c['phone'] === '3330001111';
}));

check('una fatturazione incompleta non entra e non ferma niente', fn () => provaFatturazione(static function (): bool {
    [, $scheda] = clienteFatturazione();
    $ok = salva($scheda, postFattura(['billing_number' => '', 'billing_city' => '']), ordineFatturazione());
    $vuota = salva($scheda, ['same_as_shipping' => '1'], ordineFatturazione(['shipping_street' => '']));
    $c = (array) Contact::findById($scheda);

    return !$ok && !$vuota && trim((string) ($c['street'] ?? '')) === '';
}));

check('carrello nuovo: il modulo riparte dalla fatturazione salvata, diversa e con fattura', fn () => provaFatturazione(static function (): bool {
    [$userId, $scheda] = clienteFatturazione();
    salva($scheda, postFattura(), ordineFatturazione());
    $v = valoriPagina($userId, ordineFatturazione());

    return ($v['same_as_shipping'] ?? '') === '0' && ($v['invoice'] ?? '') === '1'
        && ($v['billing_street'] ?? '') === 'Via Verdi' && ($v['billing_cf'] ?? '') === 'LVLDAA80A41L219X';
}));

check('carrello nuovo: fatturazione salvata uguale alla consegna, resta «uguale» e senza fattura', fn () => provaFatturazione(static function (): bool {
    [$userId, $scheda] = clienteFatturazione();
    // La consegna è dell'account stesso: la rubrica dà la stessa via della fatturazione.
    salva($scheda, ['same_as_shipping' => '1'], ordineFatturazione(['shipping_name' => 'Ada', 'shipping_surname' => 'Lovelace']));
    $v = valoriPagina($userId, ordineFatturazione(['shipping_name' => 'Ada', 'shipping_surname' => 'Lovelace', 'shipping_phone' => '3330001111']));

    return !isset($v['same_as_shipping']) && !isset($v['invoice']) && ($v['billing_street'] ?? '') === 'Via Roma';
}));

check('carrello con la fatturazione già scritta: valgono le scelte del carrello', fn () => provaFatturazione(static function (): bool {
    [$userId, $scheda] = clienteFatturazione();
    salva($scheda, postFattura(), ordineFatturazione());
    // Il carrello riaperto dopo un pagamento non riuscito: fatturazione uguale alla consegna, senza fattura.
    $carrello = ordineFatturazione();
    foreach (['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number'] as $campo) {
        $carrello['billing_'.$campo] = $carrello['shipping_'.$campo];
    }
    $v = valoriPagina($userId, $carrello + ['billing_type' => 'private']);

    return !isset($v['same_as_shipping']) && !isset($v['invoice']) && ($v['billing_street'] ?? '') === 'Via Roma';
}));

check('il checkout salva la fatturazione solo per chi è entrato, dopo l\'indirizzo', function () {
    $src = (string) file_get_contents(SITE.'/vendor/wonder-image/ecommerce/src/Frontend/Checkout/CheckoutPlacement.php');
    $blocco = strpos($src, 'if (!$guest && $customerId > 0) {');
    $indirizzo = strpos($src, 'AccountAddress::remember(');
    $fatturazione = strpos($src, 'AccountAddress::rememberBilling($customerId, $billing, $post)');

    return $blocco !== false && $indirizzo > $blocco && $fatturazione > $indirizzo
        && substr_count($src, 'rememberBilling(') === 1;
});

summary();
