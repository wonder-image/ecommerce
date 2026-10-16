<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Closure;

/**
 * Le regole dei servizi di un abbonamento nell'area cliente (G10b §7), senza
 * database: quale riga è del cliente, quali figlie ha, se si può togliere,
 * sotto quali righe può stare un servizio nuovo e in quali prezzi si può
 * cambiare. Replicano i controlli di `SubscriptionItems` del gestionale per
 * mostrare solo le scelte che passano; il gestionale controlla di nuovo.
 *
 * `$planOf` è la mappa id del prezzo ⇒ id del piano; `$parentsOf` dà, per un
 * piano, i piani sotto cui deve stare (vuoto se sta da solo).
 */
final class AccountSubscriptionItems
{
    /** Le righe che il cliente può ancora toccare. */
    public const OPEN = ['pending', 'active'];

    /** Le righe che contano per madri e figlie: anche quelle che terminano. */
    public const CURRENT = ['pending', 'active', 'ending'];

    /** @param array<string, mixed>|null $item */
    public static function belongs(?array $item, int $subscriptionId): bool
    {
        return is_array($item)
            && $subscriptionId > 0
            && (int) ($item['id'] ?? 0) > 0
            && (int) ($item['subscription_id'] ?? 0) === $subscriptionId
            && in_array((string) ($item['status'] ?? ''), self::OPEN, true)
            && (string) ($item['deleted'] ?? 'false') !== 'true';
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>> le righe in corso che hanno per madre `$itemId`
     */
    public static function children(array $items, int $itemId): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => $itemId > 0
            && (int) ($item['parent_item_id'] ?? 0) === $itemId
            && in_array((string) ($item['status'] ?? ''), self::CURRENT, true)));
    }

    /**
     * Si toglie una riga aperta solo se, tolte lei e le figlie, ne resta
     * un'altra aperta: togliere l'ultima è la disdetta.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function removable(array $items, int $itemId): bool
    {
        $item = self::byId($items, $itemId);

        if ($item === null || !in_array((string) ($item['status'] ?? ''), self::OPEN, true)) {
            return false;
        }

        $gone = [$itemId, ...array_map(static fn (array $child): int => (int) $child['id'], self::children($items, $itemId))];

        foreach ($items as $other) {
            if (!in_array((int) ($other['id'] ?? 0), $gone, true) && in_array((string) ($other['status'] ?? ''), self::OPEN, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le righe aperte, senza madre, di uno dei piani chiesti: dove si può
     * appoggiare un servizio nuovo.
     *
     * @param list<array<string, mixed>> $items
     * @param list<int> $motherPlanIds
     * @param array<int, int> $planOf
     * @return list<array<string, mixed>>
     */
    public static function parents(array $items, array $motherPlanIds, array $planOf): array
    {
        if ($motherPlanIds === []) {
            return [];
        }

        return array_values(array_filter($items, static fn (array $item): bool => in_array((string) ($item['status'] ?? ''), self::OPEN, true)
            && (int) ($item['parent_item_id'] ?? 0) === 0
            && in_array($planOf[(int) ($item['plan_price_id'] ?? 0)] ?? 0, $motherPlanIds, true)));
    }

    /**
     * I prezzi che si possono aggiungere: fuori quelli che vogliono una madre
     * che nell'abbonamento non c'è.
     *
     * @param list<array{code: string, price_id: int, plan_id: int, label: string}> $candidates
     * @param list<array<string, mixed>> $items
     * @param array<int, int> $planOf
     * @param Closure(int): list<int> $parentsOf
     * @return array<string, string> codice del prezzo ⇒ etichetta
     */
    public static function addable(array $candidates, array $items, array $planOf, Closure $parentsOf): array
    {
        $options = [];

        foreach ($candidates as $candidate) {
            $mothers = $parentsOf((int) $candidate['plan_id']);

            if ($mothers !== [] && self::parents($items, $mothers, $planOf) === []) {
                continue;
            }

            $options[(string) $candidate['code']] = (string) $candidate['label'];
        }

        return $options;
    }

    /**
     * I prezzi in cui si può cambiare una riga, come `SubscriptionItems::change()`:
     * con una madre, un piano che sta sotto il piano della madre; senza madre,
     * un piano che sta da solo, e se la riga ha figlie un piano sotto cui le
     * figlie possono stare. Fuori il prezzo attuale.
     *
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $items
     * @param list<array{code: string, price_id: int, plan_id: int, label: string}> $candidates
     * @param array<int, int> $planOf
     * @param Closure(int): list<int> $parentsOf
     * @return array<string, string> codice del prezzo ⇒ etichetta
     */
    public static function changeTargets(array $item, array $items, array $candidates, array $planOf, Closure $parentsOf): array
    {
        $parentId = (int) ($item['parent_item_id'] ?? 0);
        $parent = $parentId > 0 ? self::byId($items, $parentId) : null;
        $parentPlan = $parent !== null ? ($planOf[(int) ($parent['plan_price_id'] ?? 0)] ?? 0) : 0;
        $childPlans = array_values(array_unique(array_map(
            static fn (array $child): int => $planOf[(int) ($child['plan_price_id'] ?? 0)] ?? 0,
            self::children($items, (int) ($item['id'] ?? 0))
        )));
        $options = [];

        foreach ($candidates as $candidate) {
            $planId = (int) $candidate['plan_id'];

            if ((int) $candidate['price_id'] === (int) ($item['plan_price_id'] ?? 0)) {
                continue;
            }

            $mothers = $parentsOf($planId);

            if ($parentId > 0) {
                $fits = in_array($parentPlan, $mothers, true);
            } else {
                $fits = $mothers === [];

                foreach ($childPlans as $childPlan) {
                    $fits = $fits && in_array($planId, $parentsOf($childPlan), true);
                }
            }

            if ($fits) {
                $options[(string) $candidate['code']] = (string) $candidate['label'];
            }
        }

        return $options;
    }

    /**
     * Il testo della conferma di Togli: nomina le figlie, che vanno via con lei.
     *
     * @param list<string> $children le etichette delle figlie
     * @param (Closure(string, array<string, string>): string)|null $translate `__t` se manca
     */
    public static function removeConfirm(string $label, array $children, ?Closure $translate = null): string
    {
        $translate ??= static fn (string $key, array $values): string => (string) __t($key, $values);

        return $children === []
            ? $translate('ecommerce.account.subscriptions.remove_confirm', ['service' => $label])
            : $translate('ecommerce.account.subscriptions.remove_confirm_children', ['service' => $label, 'children' => implode(', ', $children)]);
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed>|null */
    private static function byId(array $items, int $itemId): ?array
    {
        foreach ($items as $item) {
            if ($itemId > 0 && (int) ($item['id'] ?? 0) === $itemId) {
                return $item;
            }
        }

        return null;
    }
}
