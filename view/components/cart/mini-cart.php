<section
    id="cart-offpage"
    class="p-f top end full-page no-interaction intro"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-label="<?=e(__t('ecommerce.cart.title'))?>"
    data-preview-url="<?=e(__r('ecommerce.cart.preview'))?>"
    data-cart-url="<?=e(__r('ecommerce.cart.index'))?>"
    style="z-index: 98"
>
    <div class="bg bg-black-20 blur-2 background-blur" data-cart-close></div>
    <div class="p-a top w-30 h-100 bg-white w-t-50 w-p-100 background-white" data-cart-close></div>
    <div class="content">
        <div class="p-a w-30 f-end w-t-50 w-p-100 h-120 c-h phone-none bg-white background-white" data-cart-close></div>

        <div class="w-30 f-end w-t-50 w-p-100 h-100 cart-content box-border pl-10 pl-p-0">
            <h2 class="subtitle"><?=e(__t('ecommerce.cart.title'))?></h2>
            <div class="p-r w-100 mt-5 h-70 o-scroll no-scrollbar box-border">
                <div class="w-100 h-100 tx-black cart-product" data-ecommerce-mini-cart-content role="status">
                    <div class="center w-100 a-c">
                        <span class="loader" aria-hidden="true"></span>
                        <p class="mt-3"><?=e(__t('ecommerce.cart.mini.loading'))?></p>
                    </div>
                </div>
            </div>
            <div class="w-100 d-grid gap-2 mt-5">
                <button class="btn btn-secondary w-100 a-c" type="button" data-cart-close><?=e(__t('ecommerce.cart.mini.continue_shopping'))?></button>
                <a class="btn btn-primary btn-lg w-100 a-c" href="<?=e(__r('ecommerce.cart.index'))?>">
                    <span class="subtitle"><?=e(__t('ecommerce.cart.mini.view_cart'))?></span>
                </a>
            </div>
        </div>

        <div class="p-a start w-70 w-t-50 h-100 phone-none" data-cart-close></div>
    </div>
</section>
