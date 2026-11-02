<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Http\Csrf;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$order = (array) ($order ?? []);
$saved = CartPresenter::money(abs((float) ($order['discount_total'] ?? 0)), (string) ($currency ?? ($order['currency'] ?? 'EUR')));
// L'importo sta in uno span suo: il JS lo riscrive a ogni riepilogo.
$savedText = str_replace('{{amount}}', '<span data-checkout-coupon-saved>'.e($saved).'</span>', e((string) __t('ecommerce.checkout.coupon_saved', ['amount' => '{{amount}}'])));
?>
<?php // Lo stacco da ciò che sta sopra: nell'accordion del carrello basta quello che il titolo aperto ha già. ?>
<form id="<?=e($id)?>" class="p-r f-start w-100 <?=e((string) ($spacing ?? 'mt-5'))?>" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
    <?=Csrf::field()?>
    <?=FormField::key('return')->hidden()->value($return)?>

    <?php // Senza codice: input e «Applica». ?>
    <div class="w-100 d-flex gap-3" data-checkout-coupon-entry<?=$code !== '' ? ' hidden' : ''?>>

        <div class="w-100">
            <?=FormField::key('code')->text()->label((string) __t('ecommerce.checkout.coupon_label'))->value($code)?>
        </div>

        <?=Button::make((string) __t('ecommerce.checkout.coupon_apply'))->type('submit')->variant('black wi-input-submit')->attr('name', 'action')->attr('value', 'apply')?>

    </div>

    <?php // Col codice applicato: il riquadro verde col risparmio e «Rimuovi», che riporta l'input. ?>
    <div class="wi-box w-100 p-4" role="status" data-checkout-coupon-applied<?=$code === '' ? ' hidden' : ''?>>
        <div class="w-100 d-flex gap-2 tx-success" style="align-items: center">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <strong><?=e(__t('ecommerce.checkout.coupon_applied_title'))?></strong>
        </div>
        <p class="w-100 text-small mt-1"><?=$savedText?></p>
        <div class="w-100 d-flex gap-3 mt-3" style="align-items: center">
            <span class="w-100 d-flex gap-2" style="align-items: center">
                <i class="bi bi-ticket-perforated" aria-hidden="true"></i>
                <strong data-checkout-coupon-code><?=e($code)?></strong>
            </span>
            <?php // «Rimuovi» come quello delle righe del carrello: cestino e testo sottolineato. ?>
            <button class="wi-cart__remove text-small" type="submit" name="action" value="remove" style="flex-shrink: 0; margin-left: auto" data-checkout-coupon-remove><i class="bi bi-trash3" aria-hidden="true"></i> <?=e(__t('ecommerce.checkout.coupon_remove'))?></button>
        </div>
    </div>

    <?php // L'errore del codice lo scrive il JS, sotto l'input del form che l'ha mandato. ?>
    <p class="text-small mt-1 tx-danger" role="alert" data-checkout-coupon-error hidden></p>

</form>
