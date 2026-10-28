<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Tracking;

/** Punto condiviso per i dati di pagina e gli eventi inviati a GTM. */
final class DataLayer
{
    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed>|list<array<string, mixed>>|null $event
     * @param int|null $userId non più usato: `user` lo manda il core, con l'id cifrato
     */
    public static function script(array $page, ?array $event = null, ?int $userId = null): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
        $base = json_encode(['page' => $page], $flags) ?: '{}';

        $script = "<script>\n"
            ."    window.dataLayer = window.dataLayer || [];\n"
            ."    window.dataLayer.push({$base});\n";

        if ($event !== null) {
            $events = array_is_list($event) ? $event : [$event];
            foreach ($events as $item) {
                $payload = json_encode($item, $flags) ?: '{}';
                $script .= "    window.dataLayer.push({ ecommerce: null });\n"
                    ."    window.dataLayer.push({$payload});\n";
            }
        }

        return $script."</script>";
    }

    /**
     * `view_item_list` di un elenco di card (`ProductListing`), con l'id e il
     * nome dell'elenco su ogni item e `index` da 1, nell'ordine in pagina:
     * lo stesso ordine dell'`ItemList` e degli articoli in evidenza.
     *
     * @param list<array<string, mixed>> $cards
     * @return array<string, mixed>
     */
    public static function itemListEvent(string $id, string $name, array $cards, string $currency): array
    {
        $items = [];

        foreach (array_values($cards) as $index => $card) {
            $item = [
                'item_id' => (string) ($card['meta']['item_id'] ?? $card['id'] ?? ''),
                'item_name' => (string) ($card['meta']['item_name'] ?? $card['name'] ?? ''),
                'price' => (float) ($card['meta']['price'] ?? 0),
                'item_list_id' => $id,
                'item_list_name' => $name,
                'index' => $index + 1,
            ];
            if (($card['meta']['item_variant'] ?? '') !== '') {
                $item['item_variant'] = (string) $card['meta']['item_variant'];
            }
            $items[] = $item;
        }

        return [
            'event' => 'view_item_list',
            'ecommerce' => [
                'currency' => $currency,
                'item_list_id' => $id,
                'item_list_name' => $name,
                'items' => $items,
            ],
        ];
    }
}
