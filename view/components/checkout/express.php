<?php

use Wonder\View\View;

$source = (string) ($config['source'] ?? 'checkout');
// Sul prodotto il riquadro segue «Aggiungi al carrello»; altrove precede il resto e chiude con «OPPURE».
$title = (string) __t($source === 'product' ? 'ecommerce.checkout.express_product' : 'ecommerce.checkout.express');
$withOr = $source !== 'product';
$termsText = (string) __t('ecommerce.checkout.express_terms');
$termsLink = (string) __t('ecommerce.checkout.express_terms_link');
$privacyJoin = (string) __t('ecommerce.checkout.express_privacy_join');
$privacyLink = (string) __t('ecommerce.checkout.express_privacy_link');
// «e l'informativa» si scrive attaccato, «and the privacy policy» no.
$privacyGap = preg_match("/['’]$/u", $privacyJoin) === 1 ? '' : ' ';
if (($style = module_asset('ecommerce', 'css/express.css')) !== '') {
    View::head('<link rel="stylesheet" href="'.e($style).'">');
}
?>
<div class="w-100 wi-express<?=$source === 'checkout' ? '' : ' mt-5'?>" data-express="<?=e(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?>" data-express-loading>
    <h2 class="text-small a-c mb-3"><?=e($title)?></h2>
    <div class="w-100" data-express-element></div>
    <p class="text-xsmall a-c mt-3"><?=e($termsText)?> <?php if ($terms !== ''): ?><a href="<?=e($terms)?>" target="_blank" rel="noopener"><?=e($termsLink)?></a><?php else: ?><?=e($termsLink)?><?php endif; ?> <?=e($privacyJoin.$privacyGap)?><?php if ($privacy !== ''): ?><a href="<?=e($privacy)?>" target="_blank" rel="noopener"><?=e($privacyLink)?></a><?php else: ?><?=e($privacyLink)?><?php endif; ?>.</p>
    <p class="text-small a-c mt-2" data-express-message role="status" hidden></p>
    <?php if ($withOr): ?><p class="wi-express__or mt-5"><?=e(__t('ecommerce.checkout.or'))?></p><?php endif; ?>
</div>
