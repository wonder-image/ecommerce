<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Http\Csrf;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$order = (array) ($order ?? []);
// I coupon si cumulano: un codice per riga, nell'ordine in cui sono entrati.
$codes = array_values(array_filter(array_map('strval', (array) ($codes ?? [])), static fn (string $code): bool => $code !== ''));
$removeLabel = (string) __t('ecommerce.checkout.coupon_remove');
$removeAria = (string) __t('ecommerce.checkout.coupon_remove_code', ['code' => '{{code}}']);
$titleOne = (string) __t('ecommerce.checkout.coupon_applied_title');
$titleMany = (string) __t('ecommerce.checkout.coupons_applied_title');
// Una riga del riquadro: il server la scrive per ogni codice, il JS la copia dal template.
$row = static fn (string $code): string => '<li class="w-100 d-flex gap-3 mt-2" style="align-items: center">'
    .'<span class="w-100 d-flex gap-2" style="align-items: center"><i class="bi bi-ticket-perforated" aria-hidden="true"></i> <strong data-checkout-coupon-code>'.e($code).'</strong></span>'
    // «Rimuovi» come quello delle righe del carrello; width auto perché .text-small lo allarga al 100%.
    .'<button class="wi-cart__remove text-small" type="submit" name="remove" value="'.e($code).'" aria-label="'.e(str_replace('{{code}}', $code, $removeAria)).'" data-label="'.e($removeAria).'" style="width: auto; flex-shrink: 0; margin-left: auto" data-checkout-coupon-remove><i class="bi bi-trash3" aria-hidden="true"></i> '.e($removeLabel).'</button>'
    .'</li>';
$saved = CartPresenter::money(abs((float) ($order['discount_total'] ?? 0)), (string) ($currency ?? ($order['currency'] ?? 'EUR')));
// L'importo sta in uno span suo: il JS lo riscrive a ogni riepilogo.
$savedText = str_replace('{{amount}}', '<span data-checkout-coupon-saved>'.e($saved).'</span>', e((string) __t('ecommerce.checkout.coupon_saved', ['amount' => '{{amount}}'])));
?>
<?php // Lo stacco da ciò che sta sopra: nell'accordion del carrello basta quello che il titolo aperto ha già. ?>
<form id="<?=e($id)?>" class="p-r f-start w-100 <?=e((string) ($spacing ?? 'mt-5'))?>" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
    <?=Csrf::field()?>
    <?=FormField::key('return')->hidden()->value($return)?>

    <?php // L'input resta anche coi codici applicati: un coupon cumulabile si aggiunge, uno che non lo è prende il posto degli altri. ?>
    <div class="w-100 d-flex gap-3" data-checkout-coupon-entry>

        <div class="w-100">
            <?=FormField::key('code')->text()->label((string) __t('ecommerce.checkout.coupon_label'))?>
        </div>

        <?=Button::make((string) __t('ecommerce.checkout.coupon_apply'))->type('submit')->variant('black wi-input-submit')->attr('name', 'action')->attr('value', 'apply')?>

    </div>

    <?php // L'errore del codice lo scrive il JS, sotto l'input del form che l'ha mandato. ?>
    <p class="text-small mt-1 tx-danger" role="alert" data-checkout-coupon-error hidden></p>

    <?php // Coi codici applicati: il riquadro verde col risparmio e un «Rimuovi» per codice. ?>
    <div class="wi-box w-100 p-4 mt-3" role="status" data-checkout-coupon-applied<?=$codes === [] ? ' hidden' : ''?>>
        <div class="w-100 d-flex gap-2 tx-success" style="align-items: center">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <strong data-checkout-coupon-title data-one="<?=e($titleOne)?>" data-many="<?=e($titleMany)?>"><?=e(count($codes) > 1 ? $titleMany : $titleOne)?></strong>
        </div>
        <p class="w-100 text-small mt-1"><?=$savedText?></p>
        <ul class="w-100 mt-1" style="list-style: none; padding: 0" data-checkout-coupon-codes><?php foreach ($codes as $code): ?><?=$row($code)?><?php endforeach; ?></ul>
        <template data-checkout-coupon-row><?=$row('')?></template>
    </div>

</form>
