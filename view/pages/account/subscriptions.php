<?php

/**
 * Area cliente → Abbonamenti (G10.14). Pagina privata del pannello: robots,
 * breadcrumb e dataLayer di pagina li dà il pannello del core; nessun dato
 * strutturato.
 *
 * @var list<array<string, mixed>> $subscriptions
 * @var string $plans_url
 */

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

                <?php if ($subscription['can_change']): ?>
                    <form id="subscription_change" class="d-flex f-wrap gap-3 a-end" method="post" action="<?=e($subscription['change_url'])?>">
                        <?=Csrf::fieldFor('post')?>
                        <label class="d-flex d-column gap-1 text-small">
                            <span><?=e((string) __t('ecommerce.account.subscriptions.change_label'))?></span>
                            <select name="price" class="wi-input" required>
                                <?php foreach ($subscription['change_options'] as $code => $label): ?>
                                    <option value="<?=e($code)?>"><?=e($label)?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?=Button::make((string) __t('ecommerce.account.subscriptions.change'))->type('submit')->variant('black')?>
                    </form>
                    <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.change_note'))?></p>
                <?php endif; ?>

                <div class="d-flex f-wrap gap-3">
                    <?php if ($subscription['can_update_card']): ?>
                        <form method="post" action="<?=e($subscription['card_url'])?>">
                            <?=Csrf::fieldFor('post')?>
                            <?=Button::make((string) __t('ecommerce.account.subscriptions.card'))->type('submit')->variant('black')->outline()?>
                        </form>
                    <?php endif; ?>
                    <?php if ($subscription['can_cancel']): ?>
                        <form method="post" action="<?=e($subscription['cancel_url'])?>" onsubmit="return confirm(<?=e((string) json_encode((string) __t('ecommerce.account.subscriptions.cancel_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP))?>);">
                            <?=Csrf::fieldFor('post')?>
                            <?=Button::make((string) __t('ecommerce.account.subscriptions.cancel'))->type('submit')->variant('danger')->outline()?>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
