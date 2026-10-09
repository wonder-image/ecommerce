<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountPagination;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** Le pagine del core più quelle dell'ecommerce: metodi di pagamento, ordini, dettaglio dell'ordine e coupon. */
class EcommerceAccountController extends AccountController
{
    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'payment-methods' => $this->paymentMethods(),
            'orders' => $this->orders(),
            'orders.show' => $this->order((string) ($parameters['code'] ?? '')),
            'coupons' => $this->coupons(),
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

    /** Gli ordini (stage order, in qualsiasi stato) della scheda del cliente, dal più recente, a dieci per pagina. */
    protected function orders(): void
    {
        $contact = $this->contact((int) $this->user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        $condition = ['stage' => 'order', 'customer_id' => $contactId];
        // Senza scheda non si cerca: customer_id 0 sono gli ordini degli ospiti.
        $total = $contactId > 0 ? (int) (self::rows(Order::find($condition, null, null, null, 'COUNT(*) AS total'))[0]['total'] ?? 0) : 0;
        $pagination = AccountPagination::make($total, AccountPagination::requested());
        // A pari data decide l'id: senza, le pagine potrebbero ripetere o saltare un ordine.
        $orders = $total > 0 ? self::rows(Order::find($condition, $pagination['limit'], 'ordered_at DESC, id', 'DESC')) : [];

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

    /**
     * Un ordine del cliente. Lo cerca per codice con le stesse regole dell'elenco (`stage = order`, la scheda del cliente):
     * ordini di altri, carrelli, ordini degli ospiti e codici inesistenti danno tutti 404, e senza scheda non si cerca nulla.
     */
    protected function order(string $code): void
    {
        $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
        $order = $code !== '' && $contactId > 0 ? Order::find(['code' => $code, 'stage' => 'order'], 1) : null;
        if (!is_array($order) || !isset($order['id']) || (int) ($order['customer_id'] ?? 0) !== $contactId) {
            $this->notFound();
        }

        $this->page(Ecommerce::viewPath('pages/account/order.php'), 'orders', [
            'title' => (string) __t('ecommerce.account.orders.order_title', ['number' => (string) $order['order_number']]),
            'seo_url' => Route::url('account.orders.show', ['code' => (string) $order['code']]),
            'order' => AccountOrder::present($order),
        ]);
    }

    /**
     * I coupon riservati al cliente e in corso (anche con tutti gli usi spesi o validi solo in negozio), a dieci per pagina.
     * Con la funzionalità spenta la pagina non c'è.
     */
    protected function coupons(): void
    {
        if (!Gestionale::feature('coupons')) {
            $this->notFound();
        }

        $contact = $this->contact((int) $this->user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        $coupons = AccountCoupons::forCustomer($contactId, date('Y-m-d H:i:s'));
        $pagination = AccountPagination::make(count($coupons), AccountPagination::requested());

        $this->page(Ecommerce::viewPath('pages/account/coupons.php'), 'coupons', [
            'title' => (string) __t('ecommerce.account.coupons.title'),
            'seo_url' => Route::url('account.coupons'),
            'errors' => $this->contactErrors($contact),
            'coupons' => array_slice($coupons, $pagination['offset'], $pagination['per_page']),
            'pagination' => $pagination,
            'base_url' => Route::url('account.coupons'),
        ]);
    }

    /**
     * Le righe di un `find()`: una riga sola, una lista o niente diventano sempre una lista.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_is_list($found) ? array_values(array_filter($found, 'is_array')) : [$found];
    }
}
