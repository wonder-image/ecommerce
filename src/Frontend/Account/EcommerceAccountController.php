<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountPagination;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** Le pagine del core più quelle dell'ecommerce: metodi di pagamento e ordini. */
class EcommerceAccountController extends AccountController
{
    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'payment-methods' => $this->paymentMethods(),
            'orders' => $this->orders(),
            default => parent::handle($action, $parameters),
        };
    }

    /** La pagina sta dentro «Dati personali», che resta la voce attiva del menu. */
    protected function paymentMethods(): void
    {
        $this->page(Ecommerce::viewPath('pages/account/payment-methods.php'), 'personal', [
            'title' => (string) __t('ecommerce.account.payment_methods.title'),
            'seo_url' => Route::url('account.payment-methods'),
            'enabled' => Ecommerce::config('account.payment_methods.enabled', false) === true,
        ]);
    }

    /** Gli ordini confermati della scheda del cliente, dal più recente, a dieci per pagina. */
    protected function orders(): void
    {
        $contact = $this->contact((int) $this->user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        $condition = ['stage' => 'order', 'customer_id' => $contactId];
        // Senza scheda non si cerca: customer_id 0 sono gli ordini degli ospiti.
        $total = $contactId > 0 ? (int) (self::rows(Order::find($condition, null, null, null, 'COUNT(*) AS total'))[0]['total'] ?? 0) : 0;
        $pagination = AccountPagination::make($total, AccountPagination::requested());
        $orders = $total > 0 ? self::rows(Order::find($condition, $pagination['limit'], 'ordered_at', 'DESC')) : [];

        $this->page(Ecommerce::viewPath('pages/account/orders.php'), 'orders', [
            'title' => (string) __t('ecommerce.account.orders.title'),
            'seo_url' => Route::url('account.orders'),
            'errors' => $this->contactErrors($contact),
            'orders' => array_map(static fn (array $order): array => [
                'number' => (string) ($order['order_number'] ?? ''),
                'date' => OrderSheet::date((string) ($order['ordered_at'] ?? '')),
                'total' => CartPresenter::money($order['total'] ?? 0, (string) (($order['currency'] ?? '') ?: 'EUR')),
                'href' => Route::url('account.orders.show', ['code' => (string) $order['code']]),
            ], $orders),
            'pagination' => $pagination,
            'base_url' => Route::url('account.orders'),
        ]);
    }

    /** Le righe di un `find()`: una riga sola, una lista o niente diventano sempre una lista. */
    protected static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) || array_key_exists('total', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
