<?php
/** php tests/integrazione/CartSessionTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');
const PACCHETTO = __DIR__.'/../..';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
// Le classi dell'ecommerce sono quelle di questo pacchetto, non quelle installate nel sito.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Wonder\\Plugin\\Ecommerce\\';
    if (str_starts_with($class, $prefix)) {
        $file = PACCHETTO.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

final class AnnullaCartSession extends RuntimeException {}

$email = 'ecommerce-cart-'.bin2hex(random_bytes(6)).'@example.com';
$token = bin2hex(random_bytes(32));

try {
    Transaction::run(static function () use ($email, $token): void {
        $created = User::create([
            'name' => 'Cliente',
            'surname' => 'Carrello',
            'email' => $email,
            'username' => create_link(explode('@', $email)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $userId = (int) ($created->insert_id ?? 0);
        $contact = Contact::create([
            'name' => 'Cliente',
            'surname' => 'Carrello',
            'email' => $email,
            'user_id' => $userId,
            'is_customer' => 'true',
            'active' => 'true',
        ]);
        $contactId = (int) ($contact->insert_id ?? 0);
        $guest = Cart::open(['cart_token' => $token, 'channel' => 'online']);
        $customer = Cart::open(['customer_id' => $contactId, 'channel' => 'online']);

        // Ospite con un cookie senza carrello: peek() non ne apre uno.
        $_COOKIE[CartSession::COOKIE] = bin2hex(random_bytes(32));
        $vuoto = CartSession::peek();
        check('peek(): ospite senza carrello, vuoto e nessun carrello creato', fn () =>
            $vuoto === ['order' => [], 'items' => [], 'removed' => []]
            && Order::find(['cart_token' => $_COOKIE[CartSession::COOKIE]], 1) === []
        );

        $_SESSION['user_id'] = $userId;
        $_COOKIE[CartSession::COOKIE] = $token;
        $peeked = CartSession::peek();
        check('peek(): cliente con carrello ospite, unisce come current(false)', fn () =>
            (int) ($peeked['order']['id'] ?? 0) === (int) ($customer['id'] ?? 0)
            && Order::findById((int) ($guest['id'] ?? 0)) === []
            && array_key_exists('removed', $peeked)
        );
        $merged = CartSession::current(false);

        check('al login il carrello ospite confluisce nel carrello del cliente', fn () =>
            CartSession::authenticated()
            && CartSession::customerId() === $contactId
            && (int) ($merged['order']['id'] ?? 0) === (int) ($customer['id'] ?? 0)
            && Order::findById((int) ($guest['id'] ?? 0)) === []
        );

        // Un cliente senza scheda: customerId() la fa nascere da customer-account, come prima.
        $senzaScheda = (int) (User::create([
            'name' => 'Cliente',
            'surname' => 'Nuovo',
            'email' => 'nuovo-'.$email,
            'username' => create_link('nuovo-'.explode('@', $email)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ])->insert_id ?? 0);
        $_SESSION['user_id'] = $senzaScheda;
        $nuovaScheda = CartSession::customerId();
        $letta = Contact::find(['user_id' => $senzaScheda], 1);
        $senzaCarrello = CartSession::peek();

        check('peek(): cliente senza carrello, vuoto e nessun carrello creato', fn () =>
            $senzaCarrello === ['order' => [], 'items' => [], 'removed' => []]
            && Order::find(['customer_id' => $nuovaScheda, 'stage' => 'cart'], 1) === []
        );

        check('senza scheda customerId() la collega e la rilegge da customer-account', fn () =>
            $nuovaScheda > 0
            && (int) ($letta['id'] ?? 0) === $nuovaScheda
            && CustomerContact::forUser($senzaScheda)['id'] === $letta['id']
        );

        throw new AnnullaCartSession();
    });
} catch (AnnullaCartSession) {
} finally {
    unset($_SESSION['user_id'], $_COOKIE[CartSession::COOKIE]);
    // La scheda letta resta in memoria oltre l'annullamento.
    CustomerContact::forget();
}

check('il test non lascia account o carrelli nel database', fn () =>
    User::find(['email' => $email], 1) === []
    && User::find(['email' => 'nuovo-'.$email], 1) === []
    && Order::find(['cart_token' => $token], 1) === []
);

summary();
