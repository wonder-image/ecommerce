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
}
