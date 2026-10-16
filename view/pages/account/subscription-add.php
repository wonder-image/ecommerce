<?php

/**
 * Area cliente → Abbonamenti → Aggiungi servizio (G10b §7). Tre passi nella
 * stessa pagina: si sceglie il prezzo, si configurano quantità,
 * personalizzazioni e madre, e l'anteprima mostra la quota pro-rata (o la
 * prova) prima della conferma. Pagina privata del pannello.
 *
 * @var array<string, mixed> $subscription di `AccountSubscriptions::present()`
 * @var array<string, string> $options codice del prezzo ⇒ etichetta
 * @var string $price il codice scelto, vuoto prima della scelta
 * @var string $price_label
 * @var array{quantity: mixed, customizations: list<array{field: mixed, help: string}>, parent: mixed}|null $fields
 * @var bool $parents_missing
 * @var array{name: string, amount: string, trial: bool}|null $preview
 * @var string $pick_url
 * @var string $preview_url
 * @var string $confirm_url
 * @var string $back_url
 */

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Http\Csrf;
use Wonder\View\View;

$title ??= '';
$active ??= 'subscriptions';
$account_panel->layout(compact('title', 'active', 'navigation', 'errors', 'notice', 'modals', 'logout_url', 'logout_token', 'head', 'user'));
?>
<div class="d-flex d-column gap-6">
    <section class="wi-box p-6 d-flex d-column gap-2">
        <h2 class="title-small"><?=e((string) $subscription['plan'])?></h2>
        <p class="text-small tx-secondary"><?=e((string) $subscription['code'])?> · <?=e((string) $subscription['total'])?></p>
    </section>

    <?php if ($fields === null): ?>
        <section class="wi-box p-6 d-flex d-column gap-4">
            <?php if ($options === []): ?>
                <p class="text"><?=e((string) __t('ecommerce.account.subscriptions.add_none'))?></p>
            <?php else: ?>
                <form id="subscription_add_pick" class="d-flex d-column gap-3" method="get" action="<?=e($pick_url)?>">
                    <?=FormField::key('price')->select(['' => (string) __t('ecommerce.subscriptions.configure.choose_option'), ...$options])->label((string) __t('ecommerce.account.subscriptions.add_label'))->required(true)?>
                    <div><?=Button::make((string) __t('ecommerce.account.subscriptions.add_submit'))->type('submit')->variant('black')?></div>
                </form>
            <?php endif; ?>
            <p class="text-small"><a class="tx-underline" href="<?=e($back_url)?>"><?=e((string) __t('ecommerce.account.subscriptions.back'))?></a></p>
        </section>
    <?php else: ?>
        <section class="wi-box p-6 d-flex d-column gap-4">
            <h2 class="title-small"><?=e($price_label)?></h2>

            <?php if ($parents_missing): ?>
                <p class="text"><?=e((string) __t('ecommerce.account.subscriptions.add_parent_missing'))?></p>
            <?php endif; ?>

            <form id="subscription_add" class="d-flex d-column gap-4" method="post" action="<?=e($preview_url)?>" novalidate>
                <?=Csrf::fieldFor('post')?>
                <?=FormField::key('price')->hidden()->value($price)?>
                <div class="w-100"><?=$fields['quantity']?></div>
                <?php foreach ($fields['customizations'] as $customization): ?>
                    <div class="w-100 d-flex d-column gap-1">
                        <?=$customization['field']?>
                        <?php if ($customization['help'] !== ''): ?>
                            <p class="text-small tx-secondary"><?=e($customization['help'])?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($fields['parent'] !== null): ?>
                    <div class="w-100 d-flex d-column gap-1">
                        <?=$fields['parent']?>
                        <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.subscriptions.configure.parent_help'))?></p>
                    </div>
                <?php endif; ?>
                <?php
                $submit = Button::make((string) __t('ecommerce.account.subscriptions.add_preview'))->type('submit')->variant('black')->outline();
                echo $parents_missing ? $submit->disabled() : $submit;
                ?>
            </form>
        </section>

        <?php if ($preview !== null): ?>
            <section class="wi-box p-6 d-flex d-column gap-4" aria-live="polite">
                <p class="text">
                    <strong><?=e((string) $preview['name'])?></strong><br>
                    <?=e((string) ($preview['trial']
                        ? __t('ecommerce.account.subscriptions.add_trial')
                        : __t('ecommerce.account.subscriptions.add_quote', ['amount' => (string) $preview['amount']])))?>
                </p>
                <form id="subscription_add_confirm" method="post" action="<?=e($confirm_url)?>">
                    <?=Csrf::fieldFor('post')?>
                    <?=Button::make((string) __t('ecommerce.account.subscriptions.add_confirm'))->type('submit')->variant('black')?>
                </form>
                <p class="text-small tx-secondary"><?=e((string) __t('ecommerce.account.subscriptions.add_edit'))?></p>
            </section>
        <?php endif; ?>

        <p class="text-small"><a class="tx-underline" href="<?=e($back_url)?>"><?=e((string) __t('ecommerce.account.subscriptions.back'))?></a></p>
    <?php endif; ?>
</div>

<?php View::end(); ?>
