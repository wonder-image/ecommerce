<?php

use Wonder\View\View;

View::layout('frontend.minimal');
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('checkout'));
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreStyle::sheet());
if (($checkoutCss = module_asset('ecommerce', 'css/checkout.css')) !== '') {
    View::head('<link rel="stylesheet" href="'.e($checkoutCss).'">');
}

?>
<main>
    <section class="wi-checkout-section">
        <div class="content">
            <div class="w-100"><?=$PAGE_CONTENT?></div>
        </div>
    </section>
</main>
<?php View::end(); ?>
