<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountController;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

/** Le pagine del core più quelle dell'ecommerce: metodi di pagamento (piano 1), ordini e coupon (piano 2). */
class EcommerceAccountController extends AccountController
{
    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'payment-methods' => $this->paymentMethods(),
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
}
