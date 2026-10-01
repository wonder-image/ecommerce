<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('account', compact('title', 'errors', 'notice') + ['active' => 'profile']);
$phone = (string) ($values['phone'] ?? $contact['phone'] ?? $user->phone ?? '');
$phonePrefix = (string) ($values['phone_prefix'] ?? $contact['phone_prefix'] ?? '+39');
$normalizedPhone = preg_replace('/\D+/', '', $phone);
$normalizedPrefix = preg_replace('/\D+/', '', $phonePrefix);
if ($normalizedPrefix !== '' && str_starts_with($normalizedPhone, $normalizedPrefix)) {
    $phone = substr($normalizedPhone, strlen($normalizedPrefix));
}
?>
<div>
    <h3 class="subtitle mb-4"><?=e(__t('ecommerce.account.personal.section'))?></h3>
    <form id="update_profile" method="post" class="d-grid col-2 col-p-1 gap-4" novalidate>
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <?=FormField::key('name')->text()->label((string) __t('ecommerce.auth.fields.name'))->required()->value($values['name'] ?? $user->name ?? '')?>
        <?=FormField::key('surname')->text()->label((string) __t('ecommerce.auth.fields.surname'))->required()->value($values['surname'] ?? $user->surname ?? '')?>
        <div class="col-2 col-p-1"><?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->value($user->email ?? '')->disabled()?></div>
        <div class="col-2 col-p-1">
            <div class="d-grid col-4 col-p-1 gap-4">
                <?=FormField::key('phone_prefix')->phonePrefix()->label((string) __t('ecommerce.auth.fields.prefix'))->required()->value($phonePrefix)?>
                <div class="col-3 col-p-1"><?=FormField::key('phone')->phone()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($phone)?></div>
            </div>
        </div>
        <div class="d-flex gap-3 col-2 col-p-1">
            <button class="btn btn-primary" type="submit"><?=e(__t('ecommerce.account.actions.save'))?></button>
            <?=Button::to(__r('ecommerce.account.index'), (string) __t('ecommerce.account.actions.cancel'))->outline()->render()?>
        </div>
    </form>
</div>
<?php View::end(); ?>
