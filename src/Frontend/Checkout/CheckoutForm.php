<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/** Il modulo del checkout come lo vuole il gestionale: scalari puliti, mai array. */
final class CheckoutForm
{
    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function data(array $post, ?object $user = null): array
    {
        $consegna = self::text($post['fulfillment_type'] ?? '');

        return [
            'email' => self::text($post['email'] ?? ($user->email ?? '')),
            'phone' => self::text($post['phone'] ?? ($user->phone ?? '')),
            'payment_method_id' => self::id($post['payment_method_id'] ?? 0),
            'fulfillment_type' => $consegna === 'pickup' ? 'pickup' : 'shipping',
            'shipping_method_id' => self::id($post['shipping_method_id'] ?? 0),
            'location_id' => self::id($post['location_id'] ?? 0),
            'customer_note' => self::text($post['customer_note'] ?? ''),
            'billing' => self::address($post, 'billing_'),
            'shipping' => self::address($post, 'shipping_'),
        ];
    }

    /** @param array<string, mixed> $method */
    public static function isManual(array $method): bool
    {
        return PaymentMethod::ledgerProvider((string) ($method['provider'] ?? '')) === 'manual';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function id(mixed $value): int
    {
        return is_scalar($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private static function address(array $post, string $prefix): array
    {
        $risultato = [];

        foreach ($post as $chiave => $valore) {
            // `shipping_method_id` è la scelta del metodo, non un campo dell'indirizzo.
            if (is_string($chiave) && $chiave !== 'shipping_method_id' && str_starts_with($chiave, $prefix) && is_scalar($valore)) {
                $risultato[substr($chiave, strlen($prefix))] = trim((string) $valore);
            }
        }

        return $risultato;
    }
}
