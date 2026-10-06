<?php
declare(strict_types=1);
const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';
chdir(SITE);
$GLOBALS['ROOT'] = SITE;
$_SERVER['DOCUMENT_ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
\Wonder\App\Theme::set('bootstrap');

use Wonder\App\Models\Contacts\{Contact, ContactAddress};
use Wonder\App\Resources\Contacts\{ContactResource, ContactAddressResource};
use Wonder\App\ResourceRegistry;
use Wonder\Auth\Frontend\AuthSession;
use Wonder\Backend\Support\{ResourcePagePresenter, ResourceFormLayoutRenderer};
use Wonder\Sql\Transaction;

final class RollbackGenericContacts extends RuntimeException {}
$session = $_SESSION ?? [];
$before = count(Contact::all());
try {
    Transaction::run(static function (): void {
        $csrf = AuthSession::csrfToken();
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            check('prefisso iniziale coerente con il paese', fn () => ContactAddressResource::mutateFormValues(['country' => 'DE'], 'create')['phone_prefix'] === '+49');
            check('prefisso esplicito preservato', fn () => ContactResource::mutateFormValues(['country' => 'DE', 'phone_prefix' => '+39'], 'edit')['phone_prefix'] === '+39');
            $_SERVER['REQUEST_METHOD'] = 'POST';
            check('prefisso vuoto inviato non sostituito dopo errore', fn () => ContactAddressResource::mutateFormValues(['country' => 'DE', 'phone_prefix' => ''], 'create')['phone_prefix'] === '');
        } finally {
            if ($requestMethod === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $requestMethod; }
        }
        check('nome privato non sostituito da ragione sociale residua', fn () => ContactResource::displayName(['type' => 'private', 'name' => 'Mario', 'surname' => 'Rossi', 'business_name' => 'Residuo']) === 'Mario Rossi');
        check('Resource generiche registrate e senza API pubbliche o eliminazione', fn () =>
            ResourceRegistry::resolve('contacts') === ContactResource::class
            && ResourceRegistry::resolve('contact-addresses') === ContactAddressResource::class
            && ContactResource::apiSchema()->get('enabled') === false
            && ContactAddressResource::pageSchema()->get('pages')['delete'] === false
        );
        check('permessi amministrativi e menu non duplicato nel gestionale', fn () =>
            ContactResource::permissionSchema()->get('backend')['update'] === ['admin', 'administrator']
            && ContactResource::navigationSchema()->get('enabled') === false
        );
        check('link per tabella conservano il pannello gestionale esistente', fn () =>
            ResourceRegistry::resolveByTable(Contact::$table) === \Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource::class
        );
        $cache = new ReflectionProperty(ResourceRegistry::class, 'resources');
        $registered = $cache->getValue();
        try {
            $cache->setValue(null, ['contacts' => ContactResource::class, 'contact-addresses' => ContactAddressResource::class]);
            check('senza gestionale il menu e il lookup usano il pannello generico', fn () =>
                ContactResource::navigationSchema()->get('enabled') === true && ResourceRegistry::resolveByTable(Contact::$table) === ContactResource::class
            );
        } finally { $cache->setValue(null, $registered); }
        $identity = ['type' => 'private', 'name' => 'Test', 'surname' => 'Generic', 'active' => 'true', '_contact_csrf' => $csrf];
        $values = ContactResource::mutateRequestValues($identity + ['user_id' => 999999, 'custom_data' => 'secret', 'is_supplier' => 'true'], 'store');
        check('solo dati contatto ammessi: niente identità account o ruoli commerciali dal POST', fn () =>
            !isset($values['user_id'], $values['custom_data'], $values['is_supplier'], $values['_contact_csrf']) && !empty($values['code'])
        );
        $created = Contact::create($values);
        $contactId = (int) ($created->insert_id ?? 0);
        check('creazione contatto senza fatturazione o password', fn () => $contactId > 0 && empty(Contact::findById($contactId)['street']));
        try { ContactResource::mutateRequestValues(array_replace($identity, ['_contact_csrf' => 'bad']), 'store'); check('CSRF mancante rifiutato', fn () => false); }
        catch (InvalidArgumentException) { check('CSRF mancante rifiutato', fn () => true); }
        $address = ['_contact_csrf' => $csrf, 'contact_id' => (string) $contactId, 'name' => 'Test', 'surname' => 'Generic',
            'country' => 'DE', 'province' => 'BE', 'city' => 'Berlin', 'cap' => '10115', 'street' => 'Teststrasse', 'number' => '1'];
        $payload = ContactAddressResource::mutateRequestValues($address + ['is_default' => 'true', 'position' => 999], 'store');
        $saved = ContactAddress::create($payload);
        $addressId = (int) ($saved->insert_id ?? 0);
        check('indirizzo completo senza etichetta valido anche nel backend', fn () => $addressId > 0 && !isset($payload['is_default'], $payload['position']));
        foreach (['province' => '', 'contact_id' => '999999999'] as $field => $badValue) {
            try { ContactAddressResource::mutateRequestValues(array_replace($address, [$field => $badValue]), 'store'); check('backend rifiuta '.$field.' non valido', fn () => false); }
            catch (InvalidArgumentException) { check('backend rifiuta '.$field.' non valido', fn () => true); }
        }
        $second = Contact::create(ContactResource::mutateRequestValues($identity, 'store'));
        try {
            ContactAddressResource::mutateRequestValues(array_replace($address, ['contact_id' => (string) $second->insert_id]), 'update', 'backend', ContactAddress::findById($addressId));
            check('indirizzo non trasferibile con POST manomesso', fn () => false);
        } catch (InvalidArgumentException) { check('indirizzo non trasferibile con POST manomesso', fn () => true); }
        $form = (new ResourcePagePresenter(ContactAddressResource::class))->form('edit', ContactAddress::findById($addressId), [], $addressId);
        $html = ResourceFormLayoutRenderer::render($form['FORM_LAYOUT']);
        check('form backend con markup Bootstrap', fn () => str_contains($html, 'form-control'));
        check('province idratate dal paese salvato', fn () => str_contains($html, 'data-wi-list-states="DE"'));
        check('provincia salvata selezionata', fn () => str_contains($html, 'value="BE" selected'));
        check('form con token CSRF e senza password', fn () => str_contains($html, 'name="_contact_csrf"') && !str_contains($html, 'name="password"'));
        $withoutStates = array_values(array_filter(array_keys(countries()), static fn ($code): bool => states($code) === []))[0];
        $foreignForm = (new ResourcePagePresenter(ContactAddressResource::class))->form('create', ['country' => $withoutStates]);
        foreach ($foreignForm['FIELDS'] as $field) {
            if ($field->name === 'province') {
                check('paese senza province non richiede una provincia inesistente', fn () => empty($field->compile()->getSchema('attributes')['required']));
            }
        }
        throw new RollbackGenericContacts();
    });
} catch (RollbackGenericContacts) {} finally { $_SESSION = $session; }
check('nessun contatto di prova lasciato nel database', fn () => count(Contact::all()) === $before);
summary();
