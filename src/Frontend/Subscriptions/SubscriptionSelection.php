<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Closure;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;

/**
 * I servizi scelti per un nuovo abbonamento, in sessione e non nel carrello
 * degli ordini. Ogni riga entra solo se la selezione intera passa il controllo
 * del gestionale (piano visibile, personalizzazioni, madre, intervalli).
 */
final class SubscriptionSelection
{
    public const KEY = 'ecommerce.subscription_selection';
    public const MAX_ITEMS = OnlineSubscriptions::MAX_ITEMS;
    public const MAX_QUANTITY = 99;

    /** Il controllo della selezione intera; i test lo sostituiscono. @var (Closure(list<array<string, mixed>>): void)|null */
    public static ?Closure $validate = null;

    /** Il cliente che compone la selezione; i test lo sostituiscono. @var (Closure(): int)|null */
    public static ?Closure $owner = null;

    /** @return list<array{price_code: string, quantity: int, customization: array<int, string>, parent: int}> */
    public static function items(): array
    {
        if (!isset($_SESSION) || !is_array($_SESSION[self::KEY] ?? null)) {
            return [];
        }

        $stored = $_SESSION[self::KEY];

        if ((int) ($stored['owner'] ?? 0) !== self::owner() || !is_array($stored['items'] ?? null)) {
            return [];
        }

        $items = [];

        foreach ($stored['items'] as $item) {
            if (is_array($item) && trim((string) ($item['price_code'] ?? '')) !== '') {
                $items[] = self::normalize($item);
            }
        }

        return $items;
    }

    /**
     * Aggiunge una riga; la selezione cambia solo se il controllo passa.
     *
     * @param array<string, mixed> $item `price_code`, `quantity`, `customization` (id ⇒ valore), `parent` (indice, -1 se nessuna)
     * @return int l'indice della nuova riga
     * @throws UserError
     */
    public static function add(array $item): int
    {
        if (trim((string) ($item['price_code'] ?? '')) === '') {
            throw UserError::make('subscription.price_unavailable');
        }

        $items = self::items();

        if (count($items) >= self::MAX_ITEMS) {
            throw UserError::make('subscription.too_many_items', ['max' => (string) self::MAX_ITEMS]);
        }

        $items[] = self::normalize($item);
        (self::$validate ?? self::check(...))($items);
        self::store($items);

        return count($items) - 1;
    }

    /** Toglie una riga e le figlie che si appoggiano a lei; le altre si rinumerano. */
    public static function remove(int $index): void
    {
        $items = self::items();

        if (!isset($items[$index])) {
            return;
        }

        $kept = [];
        $map = [];

        foreach ($items as $old => $item) {
            if ($old === $index || $item['parent'] === $index) {
                continue;
            }

            $map[$old] = count($kept);
            $kept[] = $item;
        }

        foreach ($kept as $new => $item) {
            $kept[$new]['parent'] = $item['parent'] >= 0 ? ($map[$item['parent']] ?? -1) : -1;
        }

        self::store($kept);
    }

    /** @return list<int> gli indici delle righe che hanno per madre `$index` */
    public static function children(int $index): array
    {
        return array_keys(array_filter(self::items(), static fn (array $item): bool => $item['parent'] === $index));
    }

    public static function clear(): void
    {
        if (isset($_SESSION)) {
            unset($_SESSION[self::KEY]);
        }
    }

    /**
     * Il controllo vero: posto per un altro abbonamento e righe preparate dal
     * gestionale con la fatturazione della scheda.
     *
     * @param list<array<string, mixed>> $items
     * @throws UserError
     */
    private static function check(array $items): void
    {
        $customerId = self::owner();
        Subscriptions::assertRoom($customerId);
        $contact = Contact::findById($customerId);
        OnlineSubscriptions::lines($items, OnlineSubscriptions::billingFor([], is_array($contact) ? $contact : []));
    }

    /** @param list<array<string, mixed>> $items */
    private static function store(array $items): void
    {
        if (!isset($_SESSION)) {
            return;
        }

        $_SESSION[self::KEY] = ['owner' => self::owner(), 'items' => array_values($items)];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{price_code: string, quantity: int, customization: array<int, string>, parent: int}
     */
    private static function normalize(array $item): array
    {
        $customization = [];

        foreach (is_array($item['customization'] ?? null) ? $item['customization'] : [] as $id => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if (ctype_digit((string) $id) && (int) $id > 0 && $value !== '') {
                $customization[(int) $id] = $value;
            }
        }

        $quantity = is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 1;
        $parent = $item['parent'] ?? -1;

        return [
            'price_code' => trim((string) $item['price_code']),
            'quantity' => max(1, min(self::MAX_QUANTITY, $quantity)),
            'customization' => $customization,
            'parent' => is_int($parent) || (is_string($parent) && ctype_digit($parent)) ? max(-1, (int) $parent) : -1,
        ];
    }

    private static function owner(): int
    {
        return self::$owner !== null ? (self::$owner)() : CartSession::customerId();
    }
}
