<?php
/**
 * Domande frequenti a fisarmonica: `faqs` come da `Faqs::forModel()` o
 * `Faqs::home()` ({id, question, answer}); `title` facoltativo, `null` lo toglie.
 * Senza FAQ non stampa niente.
 */

$faqs = array_values(array_filter(
    is_array($faqs ?? null) ? $faqs : [],
    static fn ($faq): bool => is_array($faq) && trim((string) ($faq['question'] ?? '')) !== ''
));

if ($faqs === []) {
    return;
}

$title = array_key_exists('title', get_defined_vars()) ? $title : __t('ecommerce.catalog.faq.title');
$headingId = 'faq-'.substr(md5(implode(',', array_column($faqs, 'id'))), 0, 8);
?>
<section class="faq w-100"<?=$title !== null ? ' aria-labelledby="'.e($headingId).'"' : ''?>>
    <?php if ($title !== null): ?>
        <h2 id="<?=e($headingId)?>" class="title tx-primary mb-6"><?=e($title)?></h2>
    <?php endif; ?>
    <div class="d-grid col-1 gap-3">
        <?php foreach ($faqs as $faq): ?>
            <div class="wi-dropdown-box">
                <h3 class="wi-dropdown-title wi-switcher text fw-600"><?=e((string) $faq['question'])?> <i class="bi bi-plus" aria-hidden="true"></i></h3>
                <div class="wi-dropdown-content">
                    <div class="text-small"><?=(string) ($faq['answer'] ?? '')?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
