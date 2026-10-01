<?php

use Wonder\Elements\Components\Alert;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\View\View;

$title ??= '';
$active ??= 'overview';
$errors = array_values(array_filter(array_map('strval', (array) ($errors ?? []))));
$notice = trim((string) ($notice ?? ''));
$user = \infoUser((int) ($_SESSION['user_id'] ?? 0), 'id');

View::layout('frontend.main');
?>
<main>
    <?php if ($notice !== ''): ?>
        <?=Alert::make($notice, 'success')->title((string) __t('ecommerce.account.notice_title'))->render()?>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <?=Alert::make(implode("\n", $errors), 'error')->title((string) __t('ecommerce.account.error_title'))->render()?>
    <?php endif; ?>
    <section class="intro">
        <div class="content">
            <div class="w-90 w-t-100">
                <p class="text-small mb-3"><a href="/"><?=e(__t('ecommerce.account.breadcrumb_home'))?></a> / <?=e(__t('ecommerce.account.title'))?></p>
                <h1 class="title mb-6"><?=e(__t('ecommerce.account.title'))?></h1>
                <div class="d-grid col-4 col-p-1 gap-6">
                    <aside class="col-1">
                        <?=View::component(Ecommerce::viewPath('components/account/navigation.php'), [
                            'active' => $active,
                            'user' => $user,
                            'csrf_token' => AuthSession::csrfToken(),
                        ])?>
                    </aside>
                    <div class="col-3 col-p-1 wi-box p-6 p-p-4">
                        <?php if ($title !== ''): ?><h2 class="subtitle mb-6"><?=e($title)?></h2><?php endif; ?>
                        <?=$PAGE_CONTENT?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<?php View::end(); ?>
