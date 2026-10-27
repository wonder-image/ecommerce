<?php

namespace Wonder\Plugin\Ecommerce\Frontend;

use LogicException;
use RuntimeException;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

/**
 * Slot delle view sigillate: il sito innesta le sue partial in `slots` del config.
 * I nomi e i dati sono contratto pubblico (docs/slots.md): si aggiungono, non si cambiano.
 */
final class Slots
{
    /** Nome dello slot => dati che riceve la partial, oltre a `slot`. */
    public const NAMES = [
        'cart.before' => ['order', 'items', 'currency'],
        'cart.after_items' => ['order', 'items', 'currency'],
        'cart.after_shipping' => ['order', 'items', 'currency'],
        'cart.after_summary' => ['order', 'items', 'currency'],
        'cart.after' => ['order', 'items', 'currency'],
        'checkout.before' => ['order', 'items', 'currency', 'guest'],
        'checkout.before_submit' => ['order', 'items', 'currency', 'guest'],
        'checkout.after_submit' => ['order', 'items', 'currency', 'guest'],
        'checkout.after' => ['order', 'items', 'currency', 'guest'],
        'checkout.completed.before' => ['result'],
        'checkout.completed.after' => ['result'],
        'account.before' => ['section', 'user'],
        'account.after' => ['section', 'user'],
        'auth.before' => ['surface'],
        'auth.after' => ['surface'],
    ];

    /** Markup delle partial dello slot; stringa vuota se il sito non ne ha. */
    public static function render(string $name, array $data = []): string
    {
        if (!isset(self::NAMES[$name])) {
            throw new LogicException("Slot ecommerce sconosciuto: {$name}");
        }

        $partials = ((array) Ecommerce::config('slots', []))[$name] ?? [];
        $html = '';
        foreach ((array) $partials as $partial) {
            $partial = trim((string) $partial);
            if ($partial === '') {
                continue;
            }
            try {
                $html .= View::component($partial, ['slot' => $name] + $data);
            } catch (RuntimeException $exception) {
                // Partial mancante: errore chiaro con il nome dello slot.
                throw new RuntimeException("Slot ecommerce {$name}: ".$exception->getMessage(), 0, $exception);
            }
        }

        return $html;
    }
}
