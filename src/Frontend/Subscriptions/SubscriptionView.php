<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

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

    /** «Pagamento mensile», «Pagamento ogni 3 mesi», «Pagamento annuale»: la scheda del prodotto. */
    public static function billing(string $unit, int $count): string
    {
        $unit = $unit === 'year' ? 'year' : 'month';

        return $count <= 1
            ? self::text('ecommerce.subscriptions.billing.'.$unit)
            : self::text('ecommerce.subscriptions.billing.'.$unit.'s', ['count' => $count]);
    }

    /** «9,90 €/Mese», «9,90 €/3 Mesi», «99,00 €/Anno»: il prezzo già formattato e il periodo. */
    public static function per(string $price, string $unit, int $count): string
    {
        $unit = $unit === 'year' ? 'year' : 'month';
        $text = $count <= 1
            ? self::text('ecommerce.subscriptions.per.'.$unit, ['price' => $price])
            : self::text('ecommerce.subscriptions.per.'.$unit.'s', ['price' => $price, 'count' => $count]);

        return $text !== '' ? $text : $price.' '.self::every($unit, $count);
    }

    /**
     * Il prezzo di una voce di `PlanCatalog::present()` col periodo, nei
     * decimali di un contesto (`product`, `card`): «30,00 €/Anno». Con
     * `regular` vero il prezzo pieno, `null` se non è barrato.
     *
     * @param array<string, mixed> $price
     */
    public static function pricePer(array $price, string $currency, string $context = 'product', bool $regular = false): ?string
    {
        $amount = $regular ? $price['regular'] : $price['amount'];

        return $amount === null ? null : self::per(
            CartPresenter::money((float) $amount, $currency, CartPresenter::decimals($context)),
            (string) $price['unit'],
            (int) $price['count'],
        );
    }

    /**
     * Il prezzo scelto nella scheda: quello del codice, se c'è, altrimenti il
     * primo. Lo usano il prezzo sotto il nome e le opzioni.
     *
     * @param list<array<string, mixed>> $prices
     * @return array<string, mixed>|null
     */
    public static function chosen(array $prices, string $code): ?array
    {
        foreach ($prices as $price) {
            if ($code !== '' && $price['code'] === $code) {
                return $price;
            }
        }

        return $prices[0] ?? null;
    }

    /** «Da 3,00 €/Mese» se i prezzi sono più d'uno, se no il prezzo così com'è. */
    public static function from(string $label, int $prices): string
    {
        if ($prices <= 1) {
            return $label;
        }

        $text = self::text('ecommerce.subscriptions.from', ['price' => $label]);

        return $text !== '' ? $text : $label;
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

    /** «Una tantum», anche senza traduzioni caricate (il JSON-LD lo usa come nome). */
    public static function oneTimeLabel(): string
    {
        return self::text('ecommerce.subscriptions.one_time_label') ?: 'Una tantum';
    }

    /**
     * Il primo addebito del riepilogo da `OnlineSubscriptions::preview()`:
     * senza prova canone (col coupon) più una tantum, in prova solo l'una
     * tantum e il canone arriva alla fine della prova (`after_trial`).
     *
     * @param array<string, mixed> $amounts
     * @return array{subtotal: float|null, discount: float, one_time: float, tax: float, total: float, after_trial: float|null}
     */
    public static function firstCharge(array $amounts, int $trial): array
    {
        $oneTime = (array) ($amounts['one_time'] ?? []);
        $fee = $trial > 0 ? null : (float) ($amounts['subtotal'] ?? 0);

        return [
            'subtotal' => $fee,
            'discount' => $trial > 0 ? 0.0 : (float) ($amounts['discount_total'] ?? 0),
            'one_time' => round((float) ($oneTime['subtotal'] ?? 0), 2),
            'tax' => round(($trial > 0 ? 0.0 : (float) ($amounts['tax_total'] ?? 0)) + (float) ($oneTime['tax_total'] ?? 0), 2),
            'total' => round((float) ($amounts['first_charge_total'] ?? $amounts['total'] ?? 0), 2),
            'after_trial' => $trial > 0 ? round((float) ($amounts['total'] ?? 0), 2) : null,
        ];
    }

    /**
     * L'una tantum di ogni servizio, per indice della selezione.
     *
     * @param array<string, mixed> $amounts
     * @return array<int, float>
     */
    public static function oneTimeLines(array $amounts): array
    {
        $out = [];

        foreach ((array) ($amounts['one_time']['lines'] ?? []) as $line) {
            $out[(int) $line['index']] = round((float) $line['line_total'], 2);
        }

        return $out;
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
