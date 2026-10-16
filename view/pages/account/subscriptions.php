<?php

/**
 * Area cliente → Abbonamenti (G10.14, G10b §7): per ogni abbonamento i suoi
 * servizi, con Togli (a fine periodo, con le figlie) e Cambia (subito, pro-rata)
 * per riga, e Aggiungi servizio. Pagina privata del pannello: robots,
 * breadcrumb e dataLayer di pagina li dà il pannello del core; nessun dato
 * strutturato.
 *
 * @var list<array<string, mixed>> $subscriptions
 * @var string $plans_url
 */

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Http\Csrf;
use Wonder\View\View;

$title ??= '';
$active ??= 'subscriptions';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<?php if ($subscriptions === []): ?>
    <div class="wi-empty-state">
        <i class="wi-empty-state__icon bi bi-arrow-repeat" aria-hidden="true"></i>
        <p><?=e((string) __t('ecommerce.account.subscriptions.empty'))?></p>
        <a class="btn btn-black mt-4" href="<?=e($plans_url)?>"><?=e((string) __t('ecommerce.account.subscriptions.discover'))?></a>
    </div>
<?php else: ?>
    <div class="d-flex d-column gap-6">
        <?php foreach ($subscriptions as $subscription): ?>
            <section class="wi-box p-5 d-flex d-column gap-3" aria-labelledby="abbonamento-<?=e($subscription['code'])?>">
                <div class="d-flex f-wrap gap-2 a-center">
                    <h2 class="subtitle" id="abbonamento-<?=e($subscription['code'])?>"><?=e($subscription['plan'])?></h2>
                    <span class="wi-badge"><?=e($subscription['status_label'])?></span>
                </div>
                <dl class="d-grid col-3 col-p-1 gap-3 text-small">
                    <div><dt class="tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.amount'))?></dt><dd><?=e($subscription['total'])?></dd></div>
                    <?php if ($subscription['trial_end'] !== ''): ?>
                        <div><dt class="tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.trial_end'))?></dt><dd><?=e($subscription['trial_end'])?></dd></div>
                    <?php endif; ?>
                    <?php if ($subscription['renewal'] !== ''): ?>
                        <div><dt class="tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.renewal'))?></dt><dd><?=e($subscription['renewal'])?></dd></div>
                    <?php endif; ?>
                    <?php if ($subscription['ends'] !== ''): ?>
                        <div><dt class="tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.ends'))?></dt><dd><?=e($subscription['ends'])?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if ($subscription['payments'] !== []): ?>
                    <div>
                        <h3 class="text-small tx-secondary mb-1"><?=e((string) __t('ecommerce.account.subscriptions.payments'))?></h3>
                        <ul class="text-small">
                            <?php foreach ($subscription['payments'] as $payment): ?>
                                <li><?=e($payment['date'])?> · <?=e($payment['amount'])?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($subscription['items'] !== []): ?>
                    <div class="d-flex d-column gap-2">
                        <h3 class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.items'))?></h3>
                        <ul class="d-flex d-column gap-3">
                            <?php foreach ($subscription['items'] as $item): ?>
                                <li class="d-flex d-column gap-2<?=$item['child'] ? ' pl-4' : ''?>">
                                    <div class="d-flex f-wrap gap-2 a-center j-between">
                                        <p class="text">
                                            <strong><?=e($item['label'])?></strong>
                                            <span class="text-small tx-secondary">· <?=e((string) __t('ecommerce.account.subscriptions.quantity', ['count' => (string) $item['quantity']]))?> · <?=e($item['total'])?></span>
                                        </p>
                                        <?php if ($item['can_remove']): ?>
                                            <?=Button::post($item['remove_url'], (string) __t('ecommerce.account.subscriptions.remove'))->variant('danger')->outline()->size('sm')->confirm($item['remove_confirm'])?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($item['starts'] !== ''): ?>
                                        <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.item_starts', ['date' => $item['starts']]))?></p>
                                    <?php endif; ?>
                                    <?php if ($item['ends'] !== ''): ?>
                                        <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.item_ends', ['date' => $item['ends']]))?></p>
                                    <?php endif; ?>
                                    <?php if ($item['scheduled'] !== ''): ?>
                                        <p class="text-small tx-secondary"><?=e($item['scheduled'])?></p>
                                    <?php endif; ?>
                                    <?php if ($item['can_change']): ?>
                                        <details>
                                            <summary class="text-small tx-underline"><?=e((string) __t('ecommerce.account.subscriptions.change'))?></summary>
                                            <form id="subscription_change_<?=e((string) $item['id'])?>" class="d-flex d-column gap-3 mt-3" method="post" action="<?=e($item['change_url'])?>">
                                                <?=Csrf::fieldFor('post')?>
                                                <?=FormField::key('price_'.$item['id'])->inputName('price')->select($item['change_options'])->label((string) __t('ecommerce.account.subscriptions.change_label'))->required(true)?>
                                                <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.change_note'))?></p>
                                                <div><?=Button::make((string) __t('ecommerce.account.subscriptions.change'))->type('submit')->variant('black')?></div>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($subscription['can_add']): ?>
                    <form id="subscription_pick_<?=e($subscription['code'])?>" class="d-flex d-column gap-3" method="get" action="<?=e($subscription['add_url'])?>">
                        <?=FormField::key('price_'.$subscription['code'])->inputName('price')->select($subscription['add_options'])->label((string) __t('ecommerce.account.subscriptions.add_label'))->required(true)?>
                        <div><?=Button::make((string) __t('ecommerce.account.subscriptions.add'))->type('submit')->variant('black')->outline()?></div>
                    </form>
                <?php endif; ?>

                <div class="d-flex f-wrap gap-3">
                    <?php if ($subscription['can_update_card']): ?>
                        <?=Button::post($subscription['card_url'], (string) __t('ecommerce.account.subscriptions.card'))->variant('black')->outline()?>
                    <?php endif; ?>
                    <?php if ($subscription['can_cancel']): ?>
                        <?=Button::post($subscription['cancel_url'], (string) __t('ecommerce.account.subscriptions.cancel'))->variant('danger')->outline()->confirm((string) __t('ecommerce.account.subscriptions.cancel_confirm'))?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
