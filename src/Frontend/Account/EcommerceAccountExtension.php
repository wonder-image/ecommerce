<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\BaseAccountExtension;
use Wonder\Http\Route;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\StoreFont;
use Wonder\Plugin\Ecommerce\Frontend\StoreStyle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/** L'ecommerce nel pannello account del core: metodi di pagamento, voci del menu del negozio (ordini, abbonamenti, coupon), font e stile. */
final class EcommerceAccountExtension extends BaseAccountExtension
{
    public function routes(): void
    {
        AccountRoutes::group(static function (): void {
            $handler = Ecommerce::handlerPath('frontend/account.php');
            Route::get('/metodi-di-pagamento/', $handler, ['account_action' => 'payment-methods'])->name('payment-methods');
            // `/ordini/` prima di `/ordini/{code}/`.
            Route::get('/ordini/', $handler, ['account_action' => 'orders'])->name('orders');
            Route::get('/ordini/{code}/', $handler, ['account_action' => 'orders.show'])->name('orders.show');
            // Il proforma in PDF dell'ordine: risponde il controller, senza la pagina.
            Route::get('/ordini/{code}/proforma/', $handler, ['account_action' => 'orders.proforma'])->name('orders.proforma');
            // La route c'è sempre: con la funzionalità spenta è il controller a dare 404.
            Route::get('/coupon/', $handler, ['account_action' => 'coupons'])->name('coupons');
            // Abbonamenti (G10): come i coupon, con la funzionalità spenta il controller dà 404.
            Route::get('/abbonamenti/', $handler, ['account_action' => 'subscriptions'])->name('subscriptions');
            // I servizi (G10b §7): aggiungi con la quota mostrata prima (GET la pagina, POST l'anteprima, poi la conferma), togli e cambia per riga.
            Route::get('/abbonamenti/{code}/aggiungi/', $handler, ['account_action' => 'subscriptions.add'])->name('subscriptions.add');
            Route::post('/abbonamenti/{code}/aggiungi/', $handler, ['account_action' => 'subscriptions.add.preview'])->name('subscriptions.add.preview');
            Route::post('/abbonamenti/{code}/aggiungi/conferma/', $handler, ['account_action' => 'subscriptions.add.confirm'])->name('subscriptions.add.confirm');
            Route::post('/abbonamenti/{code}/righe/{item}/togli/', $handler, ['account_action' => 'subscriptions.item.remove'])->name('subscriptions.item.remove')->where('item', '[0-9]+');
            Route::post('/abbonamenti/{code}/righe/{item}/cambia/', $handler, ['account_action' => 'subscriptions.item.change'])->name('subscriptions.item.change')->where('item', '[0-9]+');
            Route::post('/abbonamenti/{code}/disdici/', $handler, ['account_action' => 'subscriptions.cancel'])->name('subscriptions.cancel');
            Route::post('/abbonamenti/{code}/carta/', $handler, ['account_action' => 'subscriptions.card'])->name('subscriptions.card');
        });
    }

    /** Ordini, Abbonamenti e Coupon (questi due con la loro funzionalità) vanno subito dopo Panoramica. Il sito ritocca il menu con `account.navigation`: `false` toglie la voce, un array la ritocca. */
    public function navigation(array $items, object $user): array
    {
        $own = ['orders' => ['label' => (string) __t('ecommerce.account.orders.title'), 'href' => Route::url('account.orders'), 'icon' => 'bi bi-bag']];
        if (Gestionale::feature('online_subscriptions')) {
            $own['subscriptions'] = ['label' => (string) __t('ecommerce.account.subscriptions.title'), 'href' => Route::url('account.subscriptions'), 'icon' => 'bi bi-arrow-repeat'];
        }
        if (Gestionale::feature('coupons')) {
            $own['coupons'] = ['label' => (string) __t('ecommerce.account.coupons.title'), 'href' => Route::url('account.coupons'), 'icon' => 'bi bi-ticket-perforated'];
        }
        $at = array_search('overview', array_keys($items), true);
        $at = $at === false ? 0 : $at + 1;
        $items = array_slice($items, 0, $at, true) + $own + array_slice($items, $at, null, true);

        foreach ((array) Ecommerce::config('account.navigation', []) as $key => $item) {
            if ($item === false) {
                unset($items[$key]);
            } elseif (is_array($item)) {
                if (!isset($item['href']) && isset($item['route'])) {
                    $item['href'] = Route::url((string) $item['route']);
                }
                $items[$key] = array_replace($items[$key] ?? [], $item);
            }
        }
        return $items;
    }

    /** In Panoramica l'ultimo ordine del cliente, con il link al dettaglio; senza ordini, una riga che lo dice. */
    public function overviewRows(array $rows, object $user): array
    {
        $contactId = (int) (CustomerContact::forUser((int) ($user->id ?? 0))['id'] ?? 0);
        // Senza scheda non si cerca: customer_id 0 sono gli ordini degli ospiti.
        $last = $contactId > 0 ? EcommerceAccountController::rows(Order::find(AccountOrder::condition($contactId), 1, 'ordered_at DESC, id', 'DESC'))[0] ?? null : null;
        $label = (string) __t('ecommerce.account.orders.last');

        $rows[] = $last !== null
            ? ['key' => 'last_order'] + AccountOrder::row($last, $label)
            : ['key' => 'last_order', 'columns' => [['label' => $label, 'value' => (string) __t('ecommerce.account.orders.empty')]], 'action' => []];
        return $rows;
    }

    public function personalRows(array $rows, object $user): array
    {
        $enabled = Ecommerce::config('account.payment_methods.enabled', false) === true;
        $rows[] = [
            'key' => 'payment_methods',
            'columns' => [['label' => (string) __t('ecommerce.account.payment_methods.label'), 'value' => (string) __t('ecommerce.account.payment_methods.summary')]],
            'action' => $enabled
                ? ['label' => (string) __t('account.actions.manage'), 'href' => Route::url('account.payment-methods'), 'modal' => '', 'icon' => 'bi bi-credit-card', 'disabled' => false, 'hint' => '']
                : ['label' => (string) __t('account.actions.manage'), 'href' => '', 'modal' => '', 'icon' => 'bi bi-credit-card', 'disabled' => true, 'hint' => (string) __t('ecommerce.account.payment_methods.soon')],
        ];
        return $rows;
    }

    public function head(): string
    {
        return StoreFont::style('account').StoreStyle::sheet();
    }
}
