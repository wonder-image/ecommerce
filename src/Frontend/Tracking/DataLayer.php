<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Tracking;

/** Punto condiviso per i dati di pagina e gli eventi inviati a GTM. */
final class DataLayer
{
    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed>|null $event
     */
    public static function script(array $page, ?array $event = null, ?int $userId = null): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
        $base = json_encode([
            'user' => ['id' => $userId !== null && $userId > 0 ? $userId : null],
            'page' => $page,
        ], $flags) ?: '{}';

        $script = "<script>\n"
            ."    window.dataLayer = window.dataLayer || [];\n"
            ."    window.dataLayer.push({$base});\n";

        if ($event !== null) {
            $payload = json_encode($event, $flags) ?: '{}';
            $script .= "    window.dataLayer.push({ ecommerce: null });\n"
                ."    window.dataLayer.push({$payload});\n";
        }

        return $script."</script>";
    }
}
