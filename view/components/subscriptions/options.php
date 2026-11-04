<?php

/**
 * Il form d'acquisto della scheda di un prodotto abbonamento, allineato a
 * quello dei prodotti: i prezzi ricorrenti sono opzioni (righe `wi-choice`
 * con un radio) e c'è un solo «Aggiungi al carrello».
 *
 * Il form manda subito il POST di aggiunta: personalizzazioni (es. il
 * dominio) e servizio madre si compilano qui, come le opzioni dei prodotti,
 * senza pagine di mezzo. Dopo un errore il server torna alla scheda col
 * prezzo scelto, i valori inseriti e l'errore sul campo.
 *
 * Il radio `price` porta il codice del prezzo: il server lo legge anche
 * senza JS, lo script sposta solo `action` sul prezzo scelto e aggiorna il
 * prezzo col periodo sotto il nome, che stampa la pagina.
 *
 * Chi non ha fatto l'accesso legge che serve un account, col link alla
 * registrazione; se preme «Aggiungi al carrello» vede un alert e il form
 * non parte. Il server comunque rimanda al login (requireLogin).
 *
 * @var array<string, mixed> $plan una voce di PlanCatalog::present()
 * @var string $currency
 * @var bool $include_tax
 * @var bool $stepper la quantità col −/+, come nella scheda dei prodotti
 * @var array<string, mixed> $form SubscriptionsController::productForm()
 * @var string $plans_url
 * @var bool $authenticated
 * @var string $login_url
 * @var string $signup_url
 */

use Wonder\App\ResourceSchema\FormField;
use Wonder\Http\Csrf;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\View\View;

$prices = array_values((array) ($plan['prices'] ?? []));
if ($prices === []) return;

$authenticated = (bool) ($authenticated ?? false);
$stepper = (bool) ($stepper ?? false);
$fields = (array) ($form['fields'] ?? []);
$missing = (array) ($form['missing_parents'] ?? []);
$blocked = (bool) ($form['needs_parent'] ?? false) && ($fields['parent'] ?? null) === null;
$checked = SubscriptionView::chosen($prices, (string) ($form['price'] ?? ''));

$url = static fn (array $price): string => SubscriptionsController::addUrl((string) $price['code']);
?>
<div class="d-grid col-1 gap-3" data-subscription-options>
    <form id="add_to_cart_subscription" method="post" action="<?=e($url($checked))?>" data-subscription-form data-authenticated="<?=$authenticated ? 'true' : 'false'?>">
        <?=Csrf::field()?>
        <?php if (!$stepper): ?><?=FormField::key('quantity')->hidden()->value('1')?><?php endif; ?>

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
                        <input type="radio" name="price" value="<?=e((string) $price['code'])?>" required<?=$price['code'] === $checked['code'] ? ' checked' : ''?>
                               data-action="<?=e($url($price))?>"
                               data-per="<?=e((string) SubscriptionView::pricePer($price, $currency))?>"
                               data-regular="<?=e((string) SubscriptionView::pricePer($price, $currency, 'product', true))?>">
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

        <?php foreach ((array) ($fields['customizations'] ?? []) as $customization): ?>
            <div class="w-100 d-flex d-column gap-1 mt-3">
                <?=$customization['field']?>
                <?php if ($customization['help'] !== ''): ?>
                    <p class="text-small tx-secondary"><?=e($customization['help'])?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (($fields['parent'] ?? null) !== null): ?>
            <div class="w-100 d-flex d-column gap-1 mt-3">
                <?=$fields['parent']?>
                <p class="text-small tx-secondary"><?=e(__t('ecommerce.subscriptions.configure.parent_help'))?></p>
            </div>
        <?php elseif ($blocked && $authenticated): ?>
            <p class="text-small mt-3" data-subscription-parent-missing>
                <?=e(__t('ecommerce.subscriptions.configure.parent_missing', ['plans' => implode(', ', $missing)]))?>
                <a href="<?=e((string) ($plans_url ?? ''))?>"><?=e(__t('ecommerce.subscriptions.configure.back_to_plans'))?></a>
            </p>
        <?php endif; ?>

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
            <?php if ($stepper): ?><?=View::component(Ecommerce::viewPath('components/cart/quantity.php'))?><?php endif; ?>
            <button class="btn btn-primary w-100 wi-input-submit" type="submit"<?=$blocked && $authenticated ? ' disabled' : ''?>><?=e(__t('ecommerce.cart.add'))?></button>
        </div>
    </form>
</div>
<script>
(function () {
    var form = document.getElementById('add_to_cart_subscription');
    if (!form) { return; }
    // Il prezzo sotto il nome sta nella pagina, fuori dal componente.
    var price = document.querySelector('[data-subscription-price]');
    var regular = document.querySelector('[data-subscription-regular]');
    var show = function (radio) {
        form.action = radio.dataset.action;
        if (price) { price.textContent = radio.dataset.per; }
        if (regular) {
            regular.textContent = radio.dataset.regular;
            regular.hidden = radio.dataset.regular === '';
        }
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
