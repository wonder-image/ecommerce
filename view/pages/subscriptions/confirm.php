<?php

/**
 * Il ritorno da Stripe. Pagina privata (`NOINDEX,NOFOLLOW`, niente dati
 * strutturati, nessun breadcrumb). Finché la notifica di Stripe non arriva si
 * ricarica da sola; confermato, `purchase` parte una volta sola (la decide il
 * controller).
 *
 * @var array<string, mixed> $subscription
 * @var string $state `waiting`, `slow` (la notifica tarda: niente più ricarica), `confirmed` o `failed`
 * @var array<string, mixed>|null $purchase
 * @var string $account_url
 * @var string $plans_url
 */

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

if ($state === 'waiting') {
    View::head('<meta http-equiv="refresh" content="4">');
}

View::head(DataLayer::script([
    'type' => 'subscription_confirmation',
    'language' => __l(),
    'currency' => (string) (($subscription['currency'] ?? '') ?: 'EUR'),
], $purchase, (int) ($_SESSION['user_id'] ?? 0)));

Ecommerce::layout('checkout');
?>
<div class="w-100 wi-box p-6 a-c" aria-live="polite">
    <h1 class="title"><?=e(__t('ecommerce.subscriptions.confirm.'.$state.'_title'))?></h1>
    <p class="text mt-4"><?=e(__t('ecommerce.subscriptions.confirm.'.$state.'_text'))?></p>
    <?php if ($state === 'confirmed' || $state === 'slow'): ?>
        <a class="btn btn-primary mt-6 c-w" href="<?=e($account_url)?>"><?=e(__t('ecommerce.subscriptions.confirm.account'))?></a>
    <?php elseif ($state === 'failed'): ?>
        <a class="btn btn-primary mt-6 c-w" href="<?=e($plans_url)?>"><?=e(__t('ecommerce.subscriptions.confirm.plans'))?></a>
    <?php endif; ?>
</div>
<?php View::end(); ?>
