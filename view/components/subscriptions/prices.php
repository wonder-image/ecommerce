<?php

/**
 * I prezzi ricorrenti di un abbonamento, ognuno col suo «Aggiungi», nella
 * pagina degli abbonamenti. La scheda del prodotto usa invece `options.php`.
 *
 * Con `direct` vero il bottone manda subito il POST di aggiunta (servizio
 * senza personalizzazioni né madre); se no è un link alla scheda del
 * prodotto, dove si compilano personalizzazioni e servizio madre.
 * `data-select-item` porta l'evento GA4 `select_item`, che lo script della
 * pagina spinge al clic.
 *
 * @var array<string, mixed> $plan una voce di PlanCatalog::present()
 * @var string $currency
 * @var bool $include_tax
 * @var string $list_name
 * @var int $index il primo indice GA4
 * @var bool $direct
 */

use Wonder\Http\Csrf;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$index = (int) ($index ?? 0);
$direct = (bool) ($direct ?? false);
$listName = (string) ($list_name ?? '');
?>
<ul class="d-flex d-column gap-3" data-subscription-prices>
    <?php foreach ($plan['prices'] as $price): ?>
        <?php
        $item = PlanCatalog::trackingItem($price, (string) $plan['name'], $index++);
        $event = ['event' => 'select_item', 'ecommerce' => ($listName !== '' ? ['item_list_name' => $listName] : []) + ['items' => [$item]]];
        ?>
        <li class="d-flex d-column gap-1">
            <span class="text-small tx-secondary"><?=e($price['name'])?></span>
            <span class="subtitle">
                <?php if ($price['regular'] !== null): ?>
                    <del class="text-small tx-secondary"><?=e(CartPresenter::money($price['regular'], $currency))?></del>
                <?php endif; ?>
                <?=e(CartPresenter::money($price['amount'], $currency))?>
                <span class="text-small"><?=e(SubscriptionView::every($price['unit'], $price['count']))?></span>
            </span>
            <?php if ($price['one_time'] > 0): ?>
                <span class="text-small"><?=e(__t('ecommerce.subscriptions.one_time', ['price' => CartPresenter::money($price['one_time'], $currency)]))?></span>
            <?php endif; ?>
            <span class="text-small tx-secondary"><?=e(__t($include_tax ? 'ecommerce.subscriptions.tax_included' : 'ecommerce.subscriptions.tax_excluded'))?></span>
            <?php if ($price['trial_days'] > 0): ?>
                <span class="text-small"><?=e(__t('ecommerce.subscriptions.trial', ['days' => $price['trial_days']]))?></span>
            <?php endif; ?>
            <?php if ($price['commitment_months'] > 0): ?>
                <span class="text-small"><?=e(__t('ecommerce.subscriptions.commitment', ['months' => $price['commitment_months']]))?></span>
            <?php endif; ?>
            <?php if ($direct): ?>
                <form method="post" action="<?=e(SubscriptionsController::addUrl($price['code']))?>" class="mt-2" data-subscription-add>
                    <?=Csrf::field()?>
                    <input type="hidden" name="quantity" value="1">
                    <button class="btn btn-black w-100" type="submit"
                            data-select-item="<?=e((string) json_encode($event, $flags))?>"><?=e(__t('ecommerce.subscriptions.add'))?></button>
                </form>
            <?php else: ?>
                <a class="btn btn-black mt-2" href="<?=e((string) $plan['url'])?>"
                   data-select-item="<?=e((string) json_encode($event, $flags))?>"><?=e(__t('ecommerce.subscriptions.add'))?></a>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
