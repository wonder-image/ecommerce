<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Auth\Frontend\AccountAddressForm;
use Wonder\Auth\Frontend\AccountAddressValidation;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthValidator;
use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\Contacts\ContactAddress;
use Wonder\View\View;

final class AccountController
{
    public static function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'index' => self::overview(),
            'profile' => self::profile(),
            'billing' => self::billing(),
            'shipping' => self::shipping(),
            'shipping.create' => self::shippingEditor(),
            'shipping.edit' => self::shippingEditor((int) ($parameters['id'] ?? 0)),
            'payment-methods' => self::paymentMethods(),
            default => self::notFound(),
        };
    }

    private static function overview(): void
    {
        $user = self::user();
        $contact = self::contact((int) $user->id);
        $addresses = self::addresses((int) ($contact['id'] ?? 0));

        self::render('index', [
            'title' => (string) __t('ecommerce.account.overview_title'),
            'rows' => self::panel()->overviewRows([
                [
                    'label' => (string) __t('ecommerce.account.personal.label'),
                    'value' => self::compactLines([
                        trim((string) ($user->name ?? '').' '.(string) ($user->surname ?? '')),
                        (string) ($user->email ?? ''),
                        trim((string) ($user->phone ?? '')),
                    ]),
                    'href' => self::route('ecommerce.account.profile'),
                    'action' => (string) __t('ecommerce.account.actions.edit'),
                ],
                [
                    'label' => (string) __t('ecommerce.account.payment_methods.label'),
                    'value' => [(string) __t('ecommerce.account.payment_methods.summary')],
                    'href' => self::route('ecommerce.account.payment-methods'),
                    'action' => (string) __t('ecommerce.account.actions.manage'),
                ],
                [
                    'label' => (string) __t('ecommerce.account.billing.label'),
                    'value' => self::addressLines($contact),
                    'href' => self::route('ecommerce.account.billing'),
                    'action' => (string) __t('ecommerce.account.actions.edit'),
                ],
                [
                    'label' => (string) __t('ecommerce.account.shipping.label'),
                    'value' => [self::shippingSummary(count($addresses))],
                    'href' => self::route('ecommerce.account.shipping'),
                    'action' => (string) __t('ecommerce.account.actions.manage'),
                ],
            ], $user),
        ]);
    }

    private static function profile(): void
    {
        global $ALERT;
        $user = self::user();
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            $surname = trim((string) ($_POST['surname'] ?? ''));
            $phone = AuthValidator::canonicalPhone($_POST);

            if ($name === '') {
                $errors[] = (string) __t('ecommerce.auth.validation.errors.name_required');
            }
            if ($surname === '') {
                $errors[] = (string) __t('ecommerce.auth.validation.errors.surname_required');
            }
            if (strlen(preg_replace('/\D+/', '', $phone)) < 6) {
                $errors[] = (string) __t('ecommerce.auth.validation.errors.phone_required');
            } elseif (!\unique($phone, 'user', 'phone', (int) $user->id)) {
                $errors[] = (string) __t('ecommerce.auth.validation.errors.phone_not_unique');
            }

            $errors = array_merge($errors, self::panel()->validatePersonal($_POST, $user));
            if ($errors === []) {
                $result = \user(array_merge(self::panel()->personalUserValues($_POST, $user), [
                    'name' => $name,
                    'surname' => $surname,
                    'phone_prefix' => (string) ($_POST['phone_prefix'] ?? ''),
                    'phone' => $phone,
                    '_ecommerce_contact_phone' => (string) ($_POST['phone'] ?? ''),
                    '_ecommerce_link_contact' => true,
                    'area' => 'frontend',
                    'authority' => 'client',
                ]), (int) $user->id);

                if (empty($ALERT) && ($result->user->exists ?? false)) {
                    self::panel()->afterPersonalSaved($_POST, $result->user);
                    self::redirect(self::route('ecommerce.account.profile').'?saved=1');
                }
                $errors[] = (string) __t('ecommerce.account.errors.save');
            }
        }

        $user = self::user();
        $contact = self::contact((int) $user->id);
        self::render('profile', [
            'title' => (string) __t('ecommerce.account.personal.title'),
            'user' => $user,
            'contact' => $contact,
            'values' => $_POST,
            'errors' => $errors,
            'notice' => isset($_GET['saved']) ? (string) __t('ecommerce.account.saved') : '',
        ]);
    }

    private static function billing(): void
    {
        $user = self::user();
        $contact = self::ensureContact((int) $user->id);
        $errors = [];

        if (empty($contact['id'])) {
            self::render('message', [
                'title' => (string) __t('ecommerce.account.billing.title'),
                'active' => 'billing',
                'message' => (string) __t('ecommerce.account.errors.contact'),
            ]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            $values = self::whitelist($_POST, array_keys(Contact::billing()->labels()));
            $errors = AccountAddressValidation::validate(Contact::billing(), $values);
            $result = $errors === [] ? Contact::update($values, (int) ($contact['id'] ?? 0)) : null;

            if ($result->success ?? false) {
                self::redirect(self::route('ecommerce.account.billing').'?saved=1');
            }
            if ($errors === []) { $errors = self::saveErrors($result, Contact::billing()->labels()); }
        }

        self::render('address-form', [
            'title' => (string) __t('ecommerce.account.billing.title'),
            'active' => 'billing',
            'intro' => (string) __t('ecommerce.account.billing.intro'),
            'fields' => AccountAddressForm::fields(Contact::billing(), $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $contact, $_SERVER['REQUEST_METHOD'] === 'POST'),
            'errors' => $errors,
            'notice' => isset($_GET['saved']) ? (string) __t('ecommerce.account.saved') : '',
        ]);
    }

    private static function shipping(array $editor = []): void
    {
        $contact = self::ensureContact((int) self::user()->id);

        if (empty($contact['id'])) {
            self::render('message', [
                'title' => (string) __t('ecommerce.account.shipping.title'),
                'active' => 'shipping',
                'message' => (string) __t('ecommerce.account.errors.contact'),
            ]);
            return;
        }

        $addresses = self::addresses((int) $contact['id']);
        $forms = [0 => self::shippingFields(
            ($editor['id'] ?? -1) === 0 ? $editor['values'] : array_intersect_key($contact, array_flip(['name', 'surname', 'phone_prefix', 'phone'])),
            ($editor['id'] ?? -1) === 0
        )];
        foreach ($addresses as $address) {
            $id = (int) $address['id'];
            $submitted = ($editor['id'] ?? -1) === $id;
            $forms[$id] = self::shippingFields($submitted ? $editor['values'] : $address, $submitted);
        }
        self::render('shipping', [
            'title' => (string) __t('ecommerce.account.shipping.title'),
            'addresses' => $addresses,
            'address_forms' => $forms,
            'editor' => $editor,
            'errors' => $editor['errors'] ?? [],
            'contact' => $contact,
        ]);
    }

    private static function shippingEditor(int $addressId = 0): void
    {
        $contact = self::ensureContact((int) self::user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            self::render('message', [
                'title' => (string) __t('ecommerce.account.shipping.title'),
                'active' => 'shipping',
                'message' => (string) __t('ecommerce.account.errors.contact'),
            ]);
            return;
        }
        $address = $addressId > 0 ? ContactAddress::find(['id' => $addressId, 'contact_id' => $contactId], 1) : [];
        if ($addressId > 0 && (!is_array($address) || empty($address['id']))) {
            self::notFound();
        }
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            $keys = array_merge(['label'], array_keys(ContactAddress::address()->labels()));
            $values = self::whitelist($_POST, $keys) + ['contact_id' => $contactId];
            $errors = AccountAddressValidation::validate(ContactAddress::address(), $values);
            $result = $errors !== [] ? null : ($addressId > 0
                ? ContactAddress::update($values, $addressId)
                : ContactAddress::create($values + ['position' => count(self::addresses($contactId)) + 1]));

            if ($result->success ?? false) {
                self::redirect(self::route('ecommerce.account.shipping'));
            }
            if ($errors === []) { $errors = self::saveErrors($result, ContactAddress::address()->labels()); }
            self::shipping(['id' => $addressId, 'values' => $values, 'errors' => $errors]);
            return;
        }

        $submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
        $values = $submitted ? $_POST : (is_array($address) ? $address : []);
        if (!$submitted && $addressId === 0) {
            $values = array_intersect_key($contact, array_flip(['name', 'surname', 'phone_prefix', 'phone']));
        }
        $schema = self::shippingFields($values, $submitted);
        self::render('address-form', [
            'title' => (string) __t($addressId > 0 ? 'ecommerce.account.shipping.edit_title' : 'ecommerce.account.shipping.create_title'),
            'active' => 'shipping',
            'intro' => (string) __t('ecommerce.account.shipping.intro'),
            'fields' => $schema,
            'errors' => $errors,
        ]);
    }

    private static function paymentMethods(): void
    {
        self::render('payment-methods', [
            'title' => (string) __t('ecommerce.account.payment_methods.title'),
            'enabled' => Ecommerce::config('account.payment_methods.enabled', false) === true,
        ]);
    }

    private static function ensureContact(int $userId): array
    {
        $contact = self::contact($userId);
        if (empty($contact['id'])) {
            CustomerAccount::linkContact($userId);
            $contact = self::contact($userId);
        }

        return $contact;
    }

    private static function contact(int $userId): array
    {
        try {
            $contact = Contact::find(['user_id' => $userId], 1);
            return is_array($contact) ? $contact : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function addresses(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }

        try {
            $rows = ContactAddress::find(['contact_id' => $contactId], null, 'position', 'ASC');
            return is_array($rows) ? array_values($rows) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function addressLines(array $address): array
    {
        $lines = self::compactLines([
            trim((string) ($address['business_name'] ?? '')),
            trim((string) ($address['name'] ?? '').' '.(string) ($address['surname'] ?? '')),
            trim((string) ($address['street'] ?? '').' '.(string) ($address['number'] ?? '')),
            trim((string) ($address['cap'] ?? '').' '.(string) ($address['city'] ?? '').' '.(string) ($address['province'] ?? '')),
            trim((string) ($address['country'] ?? '')),
        ]);

        return $lines !== [] ? $lines : [(string) __t('ecommerce.account.not_configured')];
    }

    private static function compactLines(array $lines): array
    {
        return array_values(array_filter(array_map(static fn (mixed $line): string => trim((string) $line), $lines)));
    }

    private static function shippingSummary(int $count): string
    {
        return $count === 1
            ? (string) __t('ecommerce.account.shipping.one')
            : (string) __t('ecommerce.account.shipping.many', ['count' => $count]);
    }

    private static function whitelist(array $input, array $keys): array
    {
        return array_intersect_key($input, array_flip($keys));
    }

    private static function saveErrors(?object $result, array $labels): array
    {
        $errors = [];
        foreach ((array) ($result->response ?? []) as $key => $validation) {
            if (isset($validation->valid) && !$validation->valid) {
                $errors[] = (string) __t('account.validation.invalid', ['field' => (string) ($labels[$key] ?? $key)]);
            }
        }
        return $errors ?: [(string) __t('ecommerce.account.errors.save')];
    }

    private static function shippingFields(array $values, bool $submitted): array
    {
        return [
            'label' => FormField::key('label')->text()
                ->label((string) __t('ecommerce.account.shipping.address_label'))
                ->value(is_scalar($values['label'] ?? null) ? (string) $values['label'] : ''),
            ...AccountAddressForm::fields(ContactAddress::address(), $values, $submitted),
        ];
    }

    private static function user(): object
    {
        return \infoUser((int) ($_SESSION['user_id'] ?? 0), 'id');
    }

    private static function panel(): \Wonder\Auth\Frontend\AccountPanel
    {
        $class = Ecommerce::config('account.panel', EcommerceAccountPanel::class);
        if (!is_a($class, \Wonder\Auth\Frontend\AccountPanel::class, true)) {
            throw new \LogicException('Invalid ecommerce account panel');
        }
        return new $class();
    }

    private static function render(string $page, array $data = []): void
    {
        global $SEO;

        $SEO->title = (string) ($data['title'] ?? __t('ecommerce.account.title'));
        $SEO->description = (string) __t('ecommerce.account.seo.description');
        $SEO->url = self::route(match ($page) {
            'profile' => 'ecommerce.account.profile',
            'shipping' => 'ecommerce.account.shipping',
            'payment-methods' => 'ecommerce.account.payment-methods',
            'address-form' => ($data['active'] ?? '') === 'billing'
                ? 'ecommerce.account.billing'
                : 'ecommerce.account.shipping',
            default => 'ecommerce.account.index',
        });
        $SEO->breadcrumb = [];
        $SEO->robots = 'NOINDEX,NOFOLLOW';

        View::make(Ecommerce::viewPath('pages/account/'.$page.'.php'), $data + [
            'account_panel' => self::panel(),
            'user' => self::user(),
            'logout_url' => self::route('ecommerce.auth.logout'),
            'csrf_token' => AuthSession::csrfToken(),
            'errors' => [],
            'notice' => '',
        ])->render();
    }

    private static function requireCsrf(): void
    {
        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(419);
            exit('CSRF token invalid');
        }
    }

    private static function route(string $name, array $parameters = []): string
    {
        return \__r($name, $parameters) ?: '/account/';
    }

    private static function redirect(string $url): never
    {
        header('Location: '.$url);
        exit;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        exit;
    }
}
