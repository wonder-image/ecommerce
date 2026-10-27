<?php
/**
 * php tests/integrazione/CustomerPriceListTest.php
 *
 * Il cliente col listino (gestionale#78) vede il suo prezzo in scheda, card e
 * carrello; l'ospite, il feed e i dati strutturati restano sul catalogo. Le
 * classi dell'ecommerce sono quelle di questo pacchetto.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');
const PACCHETTO = __DIR__.'/../..';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
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

$supporto = dirname((string) (new ReflectionClass(Wonder\Plugin\Gestionale\Support\Orders\Checkout::class))->getFileName(), 4).'/tests/integrazione/supporto';
require $supporto.'/compra.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\CustomerPriceList;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductListing;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSnapshot;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

final class AnnullaCustomerPriceList extends RuntimeException {}

// La maglietta dei dati di prova: più varianti, più opzioni.
const MODELLO = 'maglietta-girocollo';
// Più basso di qualsiasi prezzo o campagna di prova.
const PREZZO_LISTINO = 1.23;

$nome = 'Prova listino '.bin2hex(random_bytes(4));
$model = ProductModel::find(['slug' => MODELLO, 'deleted' => 'false'], 1);

check('il modello di prova '.MODELLO.' esiste', fn () => is_array($model) && (int) ($model['id'] ?? 0) > 0);

/** L'offerta di quel prodotto nella scheda. */
function offerta(array $detail, int $productId): array
{
    foreach ((array) ($detail['offers'] ?? []) as $offer) {
        if ((int) ($offer['product_id'] ?? 0) === $productId) {
            return $offer;
        }
    }

    return [];
}

function scheda(): array
{
    CustomerPriceList::reset();

    return ProductCatalog::find(MODELLO)?->data() ?? [];
}

try {
    Transaction::run(static function () use ($model, $nome): void {
        $_SESSION = [];
        accendiFunzionalita(['orders', 'customer_price_lists']);

        $prima = scheda();
        $productId = (int) (((array) ($prima['offers'] ?? []))[0]['product_id'] ?? 0);
        $catalogo = offerta($prima, $productId);

        check('la scheda dell\'ospite ha un\'offerta da confrontare', fn () => $productId > 0 && (float) ($catalogo['price'] ?? 0) > PREZZO_LISTINO);

        $listId = (int) (PriceList::create([
            'code' => 'TST-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => $nome,
            'currency' => 'EUR',
            'prices_include_tax' => (string) (Setting::current()['catalog_prices_include_tax'] ?? 'true'),
            'active' => 'true',
        ])->insert_id ?? 0);
        PriceListItem::create(['price_list_id' => $listId, 'product_id' => $productId, 'min_quantity' => 1, 'price' => PREZZO_LISTINO, 'position' => 1]);
        PriceListItem::create(['price_list_id' => $listId, 'product_id' => $productId, 'min_quantity' => 10, 'price' => 1.00, 'position' => 2]);

        $email = 'listino-'.bin2hex(random_bytes(6)).'@example.com';
        $userId = (int) (User::create([
            'name' => 'Lia', 'surname' => 'Listino', 'email' => $email,
            'username' => create_link(explode('@', $email)[0], 'user', 'username'),
            'authority' => json_encode(['client']), 'area' => json_encode(['frontend']), 'active' => 'true',
        ])->insert_id ?? 0);
        $contactId = (int) (Contact::create([
            'name' => 'Lia', 'surname' => 'Listino', 'email' => $email, 'user_id' => $userId,
            'is_customer' => 'true', 'active' => 'true', 'country' => 'IT',
        ])->insert_id ?? 0);
        Contact::update(['price_list_id' => $listId], $contactId);

        check('l\'ospite vede ancora il prezzo di catalogo', fn () =>
            abs((float) (offerta(scheda(), $productId)['price'] ?? 0) - (float) $catalogo['price']) < 0.001
        );

        $_SESSION['user_id'] = $userId;
        $detail = scheda();
        $offer = offerta($detail, $productId);

        check('il cliente col listino vede in scheda il suo prezzo', fn () =>
            CartSession::customerId() === $contactId
            && abs((float) ($offer['price'] ?? 0) - PREZZO_LISTINO) < 0.001
            && abs((float) ($offer['catalog_price'] ?? 0) - (float) $catalogo['price']) < 0.001
        );

        check('la scheda porta anche gli scaglioni', fn () =>
            array_map(static fn (array $tier): array => [(float) $tier['min_quantity'], round((float) $tier['price'], 2)], (array) ($offer['price_list_tiers'] ?? []))
                === [[1.0, PREZZO_LISTINO], [10.0, 1.0]]
        );

        $schema = ProductCatalog::find(MODELLO)?->schemaOrg() ?? [];
        $prezzi = [];
        array_walk_recursive($schema, static function (mixed $value, mixed $key) use (&$prezzi): void {
            if (in_array($key, ['price', 'lowPrice', 'highPrice'], true)) $prezzi[] = (float) $value;
        });
        check('i dati strutturati restano sul prezzo di catalogo', fn () =>
            $prezzi !== [] && array_filter($prezzi, static fn (float $p): bool => abs($p - PREZZO_LISTINO) < 0.001) === []
        );

        $modelId = (int) $model['id'];
        $conListino = ProductListing::byModelIds([$modelId], 12, CustomerPriceList::current())[0] ?? [];
        $senza = ProductListing::byModelIds([$modelId], 12)[0] ?? [];

        check('la card del cliente parte dal prezzo di listino', fn () =>
            abs((float) ($conListino['sale_price'] ?? 0) - PREZZO_LISTINO) < 0.001
        );

        check('senza listino passato (feed) la card resta sul catalogo, anche col cliente entrato', fn () =>
            $senza !== [] && abs((float) ($senza['sale_price'] ?? 0) - PREZZO_LISTINO) > 0.001
            && abs((float) ($senza['price'] ?? 0) - PREZZO_LISTINO) > 0.001
        );

        $cartId = (int) Cart::open(['customer_id' => $contactId, 'channel' => 'online'])['id'];
        Cart::add($cartId, ['product_id' => $productId, 'quantity' => 1]);
        $cart = Cart::contents($cartId);
        $line = $cart['items'][0] ?? [];

        check('carrello e scheda danno lo stesso prezzo', fn () =>
            abs((float) ($line['unit_price'] ?? -1) - PREZZO_LISTINO) < 0.001
            && (int) (Order::findById($cartId)['price_list_id'] ?? 0) === $listId
        );

        $impronta = CheckoutSnapshot::fingerprint($cart, ['prova' => '1']);
        PriceListItem::update(['price' => 1.11], (int) (PriceListItem::find(['price_list_id' => $listId, 'min_quantity' => 10], 1)['id'] ?? 0));

        check('un listino cambiato rifà l\'anteprima del checkout', fn () =>
            $impronta !== '' && $impronta !== CheckoutSnapshot::fingerprint($cart, ['prova' => '1'])
        );

        throw new AnnullaCustomerPriceList();
    });
} catch (AnnullaCustomerPriceList) {
} finally {
    $_SESSION = [];
    CustomerPriceList::reset();
}

check('il test non lascia listini nel database', fn () => PriceList::find(['name' => $nome], 1) === []);

summary();
