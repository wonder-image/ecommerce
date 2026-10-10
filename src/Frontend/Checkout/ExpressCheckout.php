<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Throwable;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\View\View;

/**
 * I bottoni del check-out rapido (Apple Pay, Google Pay, Link) dell'elemento
 * di Stripe: in cima al checkout, nel carrello e sulla scheda prodotto. Vuoti
 * se Stripe non è collegato o non ha wallet accesi, e per l'ospite quando gli
 * ordini senza account sono spenti.
 */
final class ExpressCheckout
{
    public static function available(): bool
    {
        try {
            return self::method() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Il riquadro con l'elemento di Stripe; `$order` dà importo e valuta
     * mostrati prima che il carrello a parte esista.
     *
     * @param 'checkout'|'cart'|'product' $source
     * @param array<string, mixed> $order
     */
    public static function render(string $source, array $order = [], int $productId = 0): string
    {
        try {
            $method = self::method();
            $keys = OnlinePayment::browserKeys();
            $amount = (int) round((float) ($order['total'] ?? 0) * 100);
            if ($method === null || $keys['publishable_key'] === '' || $amount <= 0) {
                return '';
            }

            $config = [
                'source' => $source,
                'product_id' => $productId,
                'urls' => [
                    'start' => \__r('ecommerce.checkout.express.start'),
                    'shipping' => \__r('ecommerce.checkout.express.shipping'),
                    'place' => \__r('ecommerce.checkout.express.place'),
                    'cancel' => \__r('ecommerce.checkout.express.cancel'),
                ],
                'csrf' => AuthSession::csrfToken(),
                'stripe' => $keys,
                'amount' => $amount,
                'currency' => strtolower((string) ($order['currency'] ?? 'EUR')),
                'types' => $method['types'],
                'labels' => [
                    'pending_rate' => (string) __t('ecommerce.checkout.express_pending_rate'),
                    'error' => (string) __t('ecommerce.checkout.express_error'),
                    'unavailable' => (string) __t('ecommerce.checkout.express_unavailable'),
                ],
            ];

            return (string) View::component(Ecommerce::viewPath('components/checkout/express.php'), [
                'config' => $config,
                'terms' => self::legalUrl('terms_conditions'),
                'privacy' => self::legalUrl('privacy_policy'),
            ]);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return list<string> HTML già pronto, uno per riquadro
     */
    public static function buttons(array $order): array
    {
        $html = self::render('checkout', $order);

        return $html === '' ? [] : [$html];
    }

    /** @return array{id: int, types: list<string>}|null */
    private static function method(): ?array
    {
        if (!GuestCheckout::enabled() && !CartSession::authenticated()) {
            return null;
        }

        $method = Checkout::expressMethod();

        return $method['types'] === [] ? null : $method;
    }

    /** Il primo link della spunta del documento legale attivo nella lingua; vuoto se non c'è. */
    private static function legalUrl(string $docType): string
    {
        $row = sqlSelect('legal_documents', ['doc_type' => $docType, 'language_code' => __l(), 'active' => 'true'], 1, 'published_at DESC, id', 'DESC');
        if (!$row->exists) {
            return '';
        }

        $label = (string) infoLegalDocument((int) $row->id)->renderLabel;

        return preg_match('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#i', $label, $match) === 1 ? html_entity_decode($match[2]) : '';
    }
}
