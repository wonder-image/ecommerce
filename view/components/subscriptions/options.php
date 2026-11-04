<?php

/**
 * Il form d'acquisto della scheda di un prodotto abbonamento, allineato a
 * quello dei prodotti: i prezzi ricorrenti sono opzioni (righe `wi-choice`
 * con un radio) e c'è un solo «Aggiungi al carrello».
 *
 * Il radio `price` porta il codice del prezzo: il server lo legge anche
 * senza JS (POST di aggiunta o GET della configurazione), lo script sposta
 * solo `action` sul prezzo scelto e aggiorna il prezzo in alto.
 *
 * Chi non ha fatto l'accesso legge che serve un account, col link alla
 * registrazione; se preme «Aggiungi al carrello» vede un alert e il form
 * non parte. Il server comunque rimanda al login (requireLogin).
 *
 * @var array<string, mixed> $plan una voce di PlanCatalog::present()
 * @var string $currency
 * @var bool $include_tax
 * @var bool $direct il servizio entra con un clic, senza configurazione
 * @var bool $authenticated
 * @var string $login_url
 * @var string $signup_url
 */

use Wonder\Http\Csrf;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;

$prices = array_values((array) ($plan['prices'] ?? []));
if ($prices === []) return;

$direct = (bool) ($direct ?? false);
$authenticated = (bool) ($authenticated ?? false);
$checked = $prices[0];
$url = static fn (array $price): string => $direct ? SubscriptionsController::addUrl((string) $price['code']) : SubscriptionsController::configureUrl((string) $price['code']);
?>
<div class="d-grid col-1 gap-3" data-subscription-options>
    <p class="subtitle fw-600 mt-2">
        <span data-subscription-price><?=e(CartPresenter::money((float) $checked['amount'], $currency))?></span>
        <span class="text-small fw-400" data-subscription-every><?=e(SubscriptionView::billing($checked['unit'], $checked['count']))?></span>
        <s class="text fw-400 tx-dark ml-2" data-subscription-regular<?=$checked['regular'] !== null ? '' : ' hidden'?>><?=$checked['regular'] !== null ? e(CartPresenter::money((float) $checked['regular'], $currency)) : ''?></s>
    </p>

    <form id="add_to_cart_subscription" method="<?=$direct ? 'post' : 'get'?>" action="<?=e($url($checked))?>" data-subscription-form data-authenticated="<?=$authenticated ? 'true' : 'false'?>">
        <?php if ($direct): ?>
            <?=Csrf::field()?>
            <input type="hidden" name="quantity" value="1">
        <?php endif; ?>

        <fieldset class="wi-choice-group wi-choice-group--list">
            <legend class="wi-choice-group__legend text-small fw-400 mb-2"><?=e(__t('ecommerce.subscriptions.options'))?></legend>
            <div class="wi-choice-group__list">
                <?php foreach ($prices as $i => $price): ?>
                    <?php
                    $details = [SubscriptionView::billing($price['unit'], $price['count'])];
                    if ($price['one_time'] > 0) $details[] = (string) __t('ecommerce.subscriptions.one_time', ['price' => CartPresenter::money((float) $price['one_time'], $currency)]);
                    if ($price['trial_days'] > 0) $details[] = (string) __t('ecommerce.subscriptions.trial', ['days' => $price['trial_days']]);
                    if ($price['commitment_months'] > 0) $details[] = (string) __t('ecommerce.subscriptions.commitment', ['months' => $price['commitment_months']]);
                    ?>
                    <label class="wi-choice">
                        <input type="radio" name="price" value="<?=e((string) $price['code'])?>" required<?=$i === 0 ? ' checked' : ''?>
                               data-action="<?=e($url($price))?>"
                               data-amount="<?=e(CartPresenter::money((float) $price['amount'], $currency))?>"
                               data-every="<?=e(SubscriptionView::billing($price['unit'], $price['count']))?>"
                               data-regular="<?=$price['regular'] !== null ? e(CartPresenter::money((float) $price['regular'], $currency)) : ''?>">
                        <span class="wi-choice__body">
                            <span class="wi-choice__title"><?=e((string) $price['name'])?></span>
                            <span class="wi-choice__text"><?=e(implode(' · ', array_filter($details, 'strlen')))?></span>
                        </span>
                        <span class="wi-choice__aside"><?=e(CartPresenter::money((float) $price['amount'], $currency))?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <p class="text-small tx-secondary mt-2"><?=e(__t($include_tax ? 'ecommerce.subscriptions.tax_included' : 'ecommerce.subscriptions.tax_excluded'))?></p>

        <?php if (!$authenticated): ?>
            <p class="text-small mt-3" data-subscription-signup>
                <?=e(__t('ecommerce.subscriptions.signup_required'))?>
                <a href="<?=e($signup_url)?>"><?=e(__t('ecommerce.subscriptions.signup_link'))?></a>
                <?=e(__t('ecommerce.subscriptions.signup_or'))?>
                <a href="<?=e($login_url)?>"><?=e(__t('ecommerce.subscriptions.login_link'))?></a>.
            </p>
            <template data-subscription-login-alert><?=alertTheme('custom', 'warning', (string) __t('ecommerce.subscriptions.login_alert_title'), (string) __t('ecommerce.subscriptions.login_alert'))?></template>
        <?php endif; ?>

        <div class="w-100 d-flex gap-3 mt-3">
            <button class="btn btn-primary w-100 wi-input-submit" type="submit"><?=e(__t('ecommerce.cart.add'))?></button>
        </div>
    </form>
</div>
<script>
(function () {
    var form = document.getElementById('add_to_cart_subscription');
    if (!form) { return; }
    var box = form.closest('[data-subscription-options]');
    var show = function (radio) {
        form.action = radio.dataset.action;
        box.querySelector('[data-subscription-price]').textContent = radio.dataset.amount;
        box.querySelector('[data-subscription-every]').textContent = radio.dataset.every;
        var regular = box.querySelector('[data-subscription-regular]');
        regular.textContent = radio.dataset.regular;
        regular.hidden = radio.dataset.regular === '';
    };
    var checked = form.querySelector('input[name="price"]:checked');
    if (checked) { show(checked); }
    form.addEventListener('change', function (event) {
        if (event.target.name === 'price') { show(event.target); }
    });
    form.addEventListener('submit', function (event) {
        if (form.dataset.authenticated === 'true') { return; }
        event.preventDefault();
        var template = form.querySelector('[data-subscription-login-alert]');
        var alert = template && template.content.firstElementChild ? template.content.firstElementChild.cloneNode(true) : null;
        if (!alert || typeof alertContainer !== 'function') {
            window.alert(<?=json_encode((string) __t('ecommerce.subscriptions.login_alert'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>);
            return;
        }
        document.querySelectorAll('[data-subscription-alert]').forEach(function (node) { node.remove(); });
        alert.removeAttribute('id');
        alert.dataset.subscriptionAlert = '';
        alert.classList.remove('wi-show');
        alertContainer().appendChild(alert);
        requestAnimationFrame(function () { requestAnimationFrame(function () { alert.classList.add('wi-show'); }); });
    });
}());
</script>
