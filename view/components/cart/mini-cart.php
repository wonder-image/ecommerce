<section
    id="cart-offpage"
    class="p-f top end w-100 full-page o-hidden no-interaction"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-label="<?=e(__t('ecommerce.cart.title'))?>"
    data-preview-url="<?=e(__r('ecommerce.cart.preview'))?>"
    data-cart-url="<?=e(__r('ecommerce.cart.index'))?>"
    style="z-index: 102"
>
    <?php // Stato chiuso già nel markup: mini-cart.js è defer. `translate` e non `transform`: `.c-h` ha un transform !important nella lib. ?>
    <div class="bg bg-black-20 blur-4 background-blur" style="opacity: 0" data-cart-close></div>

    <?php // mini-cart.js tiene il pannello a `right: 0`: il padding destro lo porta alla fine del `.content` (se il sito definisce `--site-content-width`). ?>
    <div class="cart-content p-a top w-40 w-t-50 w-p-100 h-100 pt-4 pb-4 pl-p-4 box-border" style="right: 0; translate: 100vw 0; padding-right: calc((100% - var(--site-content-width, 100%)) / 2)">
        <div class="d-flex d-column w-100 h-100 p-6 box-border bg-white tx-black b-shadow">
            <h2 class="subtitle"><?=e(__t('ecommerce.cart.title'))?></h2>

            <div class="p-r w-100 mt-5 min-h-0 o-auto no-scrollbar box-border" style="flex: 1">
                <div class="w-100 h-100 cart-product" data-ecommerce-mini-cart-content role="status">
                    <div class="center w-100 a-c">
                        <span class="loader" aria-hidden="true"></span>
                        <p class="mt-3"><?=e(__t('ecommerce.cart.mini.loading'))?></p>
                    </div>
                </div>
            </div>

            <?php // Col carrello vuoto le azioni non servono: le nasconde mini-cart.js. ?>
            <div class="w-100 mt-5 d-none" data-cart-actions>
                <div class="w-100 d-grid col-1 gap-2 mb-3">
                    <button class="btn btn-dark-o w-100 a-c" type="button" data-cart-close>
                        <i class="bi bi-arrow-left mr-2" aria-hidden="true"></i><?=e(__t('ecommerce.cart.mini.continue_shopping'))?>
                    </button>
                    <a class="btn btn-dark-o w-100 a-c" href="<?=e(__r('ecommerce.cart.index'))?>">
                        <i class="bi bi-bag mr-2" aria-hidden="true"></i><?=e(__t('ecommerce.cart.mini.view_cart'))?>
                    </a>
                </div>
                <a class="btn btn-primary btn-lg btn-arrow w-100 a-c fw-600" href="<?=e(__r('ecommerce.checkout.index'))?>" data-cart-checkout>
                    <?=e(__t('ecommerce.cart.mini.checkout'))?><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </div>
</section>
