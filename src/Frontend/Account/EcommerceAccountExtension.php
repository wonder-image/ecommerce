<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\BaseAccountExtension;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\StoreFont;
use Wonder\Plugin\Ecommerce\Frontend\StoreStyle;
use Wonder\Plugin\Gestionale\Gestionale;

/** L'ecommerce nel pannello account del core: metodi di pagamento, voci del menu del negozio, font e stile. */
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
            // La route c'è sempre: con la funzionalità spenta è il controller a dare 404.
            Route::get('/coupon/', $handler, ['account_action' => 'coupons'])->name('coupons');
        });
    }

    /** Ordini, e Coupon se la funzionalità è accesa, vanno subito dopo Panoramica. Il sito ritocca il menu con `account.navigation`: `false` toglie la voce, un array la ritocca. */
    public function navigation(array $items, object $user): array
    {
        $own = ['orders' => ['label' => (string) __t('ecommerce.account.orders.title'), 'href' => Route::url('account.orders'), 'icon' => 'bi bi-bag']];
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
