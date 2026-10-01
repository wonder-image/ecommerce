<?php

$active = (string) ($active ?? 'overview');
$fullName = trim((string) ($user->name ?? '').' '.(string) ($user->surname ?? ''));
$items = [
    ['key' => 'overview', 'route' => 'ecommerce.account.index', 'icon' => 'bi bi-grid', 'label' => __t('ecommerce.account.navigation.overview')],
    ['key' => 'profile', 'route' => 'ecommerce.account.profile', 'icon' => 'bi bi-person', 'label' => __t('ecommerce.account.navigation.profile')],
    ['key' => 'billing', 'route' => 'ecommerce.account.billing', 'icon' => 'bi bi-receipt', 'label' => __t('ecommerce.account.navigation.billing')],
    ['key' => 'shipping', 'route' => 'ecommerce.account.shipping', 'icon' => 'bi bi-geo-alt', 'label' => __t('ecommerce.account.navigation.shipping')],
    ['key' => 'payment-methods', 'route' => 'ecommerce.account.payment-methods', 'icon' => 'bi bi-credit-card', 'label' => __t('ecommerce.account.navigation.payment_methods')],
];
?>
<div class="wi-box p-4">
    <?php if ($fullName !== ''): ?>
        <p class="text-small mb-1"><?=e(__t('ecommerce.account.navigation.welcome'))?></p>
        <p class="subtitle mb-4"><?=e($fullName)?></p>
    <?php endif; ?>
    <nav class="d-grid col-1 gap-2" aria-label="<?=e(__t('ecommerce.account.navigation.label'))?>">
        <?php foreach ($items as $item): ?>
            <?php $isActive = $active === $item['key']; ?>
            <a
                class="d-flex gap-3 p-3 b-r-10 <?= $isActive ? 'bg-primary-10 tx-primary' : '' ?>"
                href="<?=e(__r($item['route']))?>"
                style="justify-content:space-between;text-decoration:none;"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            >
                <span><i class="<?=e($item['icon'])?>" style="margin-right:.75rem;" aria-hidden="true"></i><?=e($item['label'])?></span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
        <form id="logout" method="post" action="<?=e(__r('ecommerce.auth.logout'))?>">
            <input type="hidden" name="csrf_token" value="<?=e($csrf_token)?>">
            <button class="btn btn-primary-o w-100" type="submit">
                <i class="bi bi-box-arrow-right" style="margin-right:.75rem;" aria-hidden="true"></i><?=e(__t('ecommerce.account.navigation.logout'))?>
            </button>
        </form>
    </nav>
</div>
