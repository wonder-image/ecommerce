<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Support\Text\Slug as TextSlug;

/**
 * Quale opzione è già scelta quando la scheda si apre con
 * `?taglia=s&materiale=cotone`. La chiave è lo slug dell'attributo, il valore
 * lo slug dell'etichetta oppure l'id del valore.
 *
 * Pura: confronta la query con i gruppi e le offerte che la scheda ha già
 * letto, e la query non arriva mai in SQL. Quello che non combacia si ignora.
 */
final class OptionQuery
{
    /**
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $offers
     * @param array<mixed> $query
     */
    public static function match(array $groups, array $offers, array $query): ?int
    {
        $wanted = self::wanted($groups, $query);

        if ($wanted === []) {
            return null;
        }

        $first = null;

        foreach ($offers as $offer) {
            $attributes = (array) ($offer['attributes'] ?? []);

            foreach ($wanted as $groupId => $valueId) {
                if ((string) ($attributes[$groupId] ?? '') !== $valueId) {
                    continue 2;
                }
            }

            if (!empty($offer['available'])) {
                return (int) $offer['product_id'];
            }

            $first ??= (int) $offer['product_id'];
        }

        return $first;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param array<mixed> $query
     * @return array<string, string> [id del gruppo => id del valore]
     */
    public static function wanted(array $groups, array $query): array
    {
        $query = array_change_key_case(ProductUrl::query($query), CASE_LOWER);
        $wanted = [];

        foreach ($groups as $group) {
            $key = strtolower(trim((string) ($group['slug'] ?? '')));

            if ($key === '' || !isset($query[$key])) {
                continue;
            }

            $asked = strtolower($query[$key]);

            foreach ((array) ($group['values'] ?? []) as $value) {
                $id = (string) ($value['id'] ?? '');
                $slug = (string) ($value['slug'] ?? self::slug((string) ($value['label'] ?? '')));

                if ($asked === strtolower($id) || ($slug !== '' && $asked === $slug)) {
                    $wanted[(string) $group['id']] = $id;
                    break;
                }
            }
        }

        return $wanted;
    }

    /** `Blu Notte` → `blu-notte`: la stessa regola degli slug del gestionale. */
    public static function slug(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = str_replace('_', '-', TextSlug::make($label));

        return trim((string) preg_replace('/-+/', '-', $slug), '-');
    }
}
