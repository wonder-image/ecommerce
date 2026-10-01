<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Client;

use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthValidator;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;

final class CustomerAccount
{
    public static function hook(array $post, array $values, object $user): object
    {
        if ((int) ($user->id ?? 0) > 0 && !empty($post['_ecommerce_link_contact'])) {
            self::linkContact((int) $user->id, $post);
        }

        return (object) ['values' => $values, 'user' => $user];
    }

    public static function validate(array $post, array $values, mixed $unused = null, mixed $modifyId = null): object
    {
        global $ALERT;

        if (!empty($post['_ecommerce_signup_completion'])) {
            $errors = AuthValidator::completion($post, empty($post['_ecommerce_federated_completion']));
        } elseif (!empty($post['_ecommerce_signup_request'])) {
            $errors = AuthValidator::signupRequest($post);
        } else {
            $errors = [];
        }

        if ($errors !== []) {
            $ALERT = 900;
        }

        return (object) ['post' => [], 'errors' => $errors];
    }

    public static function info(mixed $value, string $key = 'user_id'): object
    {
        $row = Contact::find([$key => $value], 1);

        return (object) array_merge(['exists' => is_array($row) && !empty($row['id'])], is_array($row) ? $row : []);
    }

    public static function linkContact(int $userId, array $input = []): object
    {
        $user = \infoUser($userId, 'id');
        if (!($user->exists ?? false)) {
            return (object) ['success' => false, 'reason' => 'user_not_found'];
        }

        $byUser = Contact::find(['user_id' => $userId], 1);
        $byEmail = Contact::find(['email' => (string) ($user->email ?? '')], 1);
        $contact = is_array($byUser) && !empty($byUser['id']) ? $byUser : $byEmail;

        if (is_array($contact) && !empty($contact['id'])
            && (int) ($contact['user_id'] ?? 0) > 0
            && (int) $contact['user_id'] !== $userId) {
            return (object) ['success' => false, 'reason' => 'contact_link_conflict'];
        }

        $values = [
            'name' => (string) ($user->name ?? ''),
            'surname' => (string) ($user->surname ?? ''),
            'email' => (string) ($user->email ?? ''),
            'user_id' => $userId,
            'is_customer' => 'true',
            'active' => 'true',
        ];

        if (!empty($input['phone'])) {
            $values['phone_prefix'] = preg_replace('/[^0-9+]/', '', (string) ($input['phone_prefix'] ?? ''));
            $values['phone'] = preg_replace('/\D+/', '', (string) ($input['_ecommerce_contact_phone'] ?? $input['phone']));
        }

        $result = is_array($contact) && !empty($contact['id'])
            ? Contact::update($values, (int) $contact['id'])
            : Contact::create($values);

        return (object) [
            'success' => (bool) ($result->success ?? false),
            'reason' => ($result->success ?? false) ? '' : 'contact_write_failed',
            'contact_id' => (int) ($contact['id'] ?? $result->insert_id ?? 0),
        ];
    }
}
