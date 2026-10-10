<?php

$quantity = max(1, (int) ($quantity ?? 1));
?>
<div class="wi-quantity" data-quantity>
    <button class="btn btn-black-o" type="button" data-quantity-step="-1" aria-label="<?=e(__t('ecommerce.cart.decrease'))?>">−</button>
    <input type="number" name="quantity" min="1" step="1" value="<?=e((string) $quantity)?>" inputmode="numeric" aria-label="<?=e(__t('ecommerce.cart.quantity'))?>">
    <button class="btn btn-black-o" type="button" data-quantity-step="1" aria-label="<?=e(__t('ecommerce.cart.increase'))?>">+</button>
</div>
<script>
    // −/+ cambiano solo il numero: il modulo parte con «Aggiungi al carrello».
    window.wiQuantity ??= document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-quantity-step]');
        const input = button?.closest('[data-quantity]')?.querySelector('input[name="quantity"]');
        if (!input) return;
        if (!input.value || Number(input.value) < 1) input.value = '1';
        button.dataset.quantityStep === '-1' ? input.stepDown() : input.stepUp();
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }) ?? true;
</script>
