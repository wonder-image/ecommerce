<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;

/** Le parole delle pagine degli abbonamenti: frequenza, limiti, stati. */
final class SubscriptionView
{
    /** «al mese», «ogni 3 mesi», «all'anno», «ogni 2 anni». */
    public static function every(string $unit, int $count): string
    {
        $unit = $unit === 'year' ? 'year' : 'month';

        return $count <= 1
            ? self::text('ecommerce.subscriptions.every.'.$unit)
            : self::text('ecommerce.subscriptions.every.'.$unit.'s', ['count' => $count]);
    }

    /**
     * Il nome di un limite: quello scritto in `ecommerce.subscriptions.features.<chiave>`
     * se il sito lo traduce, altrimenti la chiave resa leggibile
     * («max_progetti» → «Max progetti»).
     */
    public static function featureLabel(string $key): string
    {
        $path = 'ecommerce.subscriptions.features.'.$key;
        $text = self::text($path);

        if ($text !== '' && $text !== $path) {
            return $text;
        }

        $words = trim(str_replace(['_', '-'], ' ', $key));

        return $words === '' ? $key : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    /** «Attivo», «In prova»… */
    public static function status(string $status): string
    {
        return self::text('ecommerce.subscriptions.status.'.$status);
    }

    /** @param array<string, mixed> $replace */
    private static function text(string $key, array $replace = []): string
    {
        try {
            $text = (string) __t($key, $replace);
        } catch (Throwable) {
            return '';
        }

        return $text === $key ? '' : $text;
    }
}
