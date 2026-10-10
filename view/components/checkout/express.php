<?php
$termsText = (string) __t('ecommerce.checkout.express_terms');
$termsLink = (string) __t('ecommerce.checkout.express_terms_link');
?>
<div class="w-100" data-express="<?=e(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?>">
    <div class="w-100" data-express-element></div>
    <p class="text-small a-c mt-2"><?=e($termsText)?> <?php if ($terms !== ''): ?><a href="<?=e($terms)?>" target="_blank" rel="noopener"><?=e($termsLink)?></a><?php else: ?><?=e($termsLink)?><?php endif; ?></p>
    <p class="text-small a-c mt-2" data-express-message role="status" hidden></p>
</div>
