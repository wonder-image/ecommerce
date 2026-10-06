<?php
// CLI-only fixture: renders the real components without bypassing site auth.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';
chdir($site);
$GLOBALS['ROOT'] = $site;
$_SERVER['DOCUMENT_ROOT'] = $site;
require $site.'/vendor/autoload.php';
require $site.'/vendor/wonder-image/app/wonder-image.php';
if (($argv[1] ?? '') === 'backend') {
    \Wonder\App\Theme::set('bootstrap');
    $resource = \Wonder\App\Resources\Contacts\ContactAddressResource::class;
    $form = (new \Wonder\Backend\Support\ResourcePagePresenter($resource))->form('create', [
        'country' => 'DE', 'province' => 'BE', 'name' => 'Test', 'surname' => 'Recipient',
    ]);
    echo '<div class="container p-4">'.\Wonder\Backend\Support\ResourceFormLayoutRenderer::render($form['FORM_LAYOUT']).'</div>';
    exit;
}
$address = ($argv[1] ?? '') === 'billing'
    ? \Wonder\App\Models\Contacts\Contact::billing()
    : \Wonder\App\Models\Contacts\ContactAddress::address();
$fields = \Wonder\Auth\Frontend\AccountAddressForm::fields($address, ['province' => 'BG']);
if (($argv[1] ?? '') === 'modal') {
    $fields = ['label' => \Wonder\App\ResourceSchema\FormField::key('label')->text()->label('Etichetta'), ...$fields];
    echo \Wonder\Elements\Components\Button::make('Aggiungi indirizzo')->attr('id', 'open-address')->opensModal('preview-address')->render();
    echo \Wonder\Auth\Frontend\AccountAddressModal::make('preview-address', 'Indirizzo', $fields, '/account/shipping-addresses/new/', '/account/shipping-addresses/', 'test-token')->render();
    exit;
}
echo '<section><div style="width:100%;padding:24px;box-sizing:border-box"><form>';
echo \Wonder\Auth\Frontend\AccountAddressForm::layout($fields)->render();
echo '</form></div></section>';
