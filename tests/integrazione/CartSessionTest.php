<?php
/** php tests/integrazione/CartSessionTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
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

        $_SESSION['user_id'] = $userId;
        $_COOKIE[CartSession::COOKIE] = $token;
        $merged = CartSession::current(false);

        check('al login il carrello ospite confluisce nel carrello del cliente', fn () =>
            CartSession::authenticated()
            && CartSession::customerId() === $contactId
            && (int) ($merged['order']['id'] ?? 0) === (int) ($customer['id'] ?? 0)
            && Order::findById((int) ($guest['id'] ?? 0)) === []
        );

        throw new AnnullaCartSession();
    });
} catch (AnnullaCartSession) {
} finally {
    unset($_SESSION['user_id'], $_COOKIE[CartSession::COOKIE]);
}

check('il test non lascia account o carrelli nel database', fn () =>
    User::find(['email' => $email], 1) === []
    && Order::find(['cart_token' => $token], 1) === []
);

summary();
