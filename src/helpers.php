<?php

function ecommerceClient(array $post, array $values, object $user, mixed $modifyId = null): object
{
    return \Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount::hook($post, $values, $user);
}

function validateEcommerceClient(array $post, array $values, mixed $user = null, mixed $modifyId = null): object
{
    return \Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount::validate($post, $values, $user, $modifyId);
}

function infoEcommerceClient(mixed $value, string $key = 'user_id'): object
{
    return \Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount::info($value, $key);
}
