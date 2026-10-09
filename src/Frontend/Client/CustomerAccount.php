<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Client;

use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthValidator;
use Wonder\App\Models\Contacts\Contact;

final class CustomerAccount
{
    public static function hook(array $post, array $values, object $user): object
    {
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
        if (isset($input['_ecommerce_contact_phone'])) {
            $input['phone'] = $input['_ecommerce_contact_phone'];
        }
        return \Wonder\Auth\Frontend\ContactAccount::link($userId, $input);
    }
}
