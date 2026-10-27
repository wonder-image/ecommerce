<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Throwable;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceLists;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

/**
 * Il listino del cliente loggato (gestionale#78), letto una volta per richiesta.
 *
 * Riservato: lo chiede solo chi mostra prezzi a quel cliente (scheda, card,
 * ricerca). Feed, llms.txt e dati strutturati restano sul prezzo di catalogo.
 */
final class CustomerPriceList
{
    private static bool $loaded = false;

    /** @var array<string, mixed>|null */
    private static ?array $list = null;

    /** @var array{country: string, type: string} */
    private static array $customer = ['country' => 'IT', 'type' => 'private'];

    /** @var array<int, float> aliquota per categoria IVA */
    private static array $rates = [];

    /** @return array<string, mixed>|null null per l'ospite, senza listino o con un listino che oggi non vale */
    public static function current(): ?array
    {
        if (self::$loaded) {
            return self::$list;
        }

        self::$loaded = true;

        try {
            $contactId = CartSession::customerId();
            $listId = PriceLists::forContact($contactId);

            if ($listId <= 0) {
                return null;
            }

            self::$list = PriceLists::load($listId, date('Y-m-d H:i:s'), (string) Ecommerce::config('catalog.currency', 'EUR'));

            // Paese e tipo come li legge il carrello dalla fatturazione.
            $contact = Contact::findById($contactId);
            $contact = is_array($contact) ? $contact : [];
            self::$customer = [
                'country' => strtoupper(trim((string) ($contact['country'] ?? ''))) ?: 'IT',
                'type' => trim((string) ($contact['pi'] ?? '')) !== '' ? 'business' : 'private',
            ];
        } catch (Throwable) {
            self::$list = null;
        }

        return self::$list;
    }

    /** Il prezzo unitario a listino nella base IVA del catalogo; 0 se il prodotto non è nel listino. */
    public static function price(?array $list, int $productId, int $taxCategoryId, float $quantity = 1.0): float
    {
        if ($list === null || !isset($list['items'][$productId])) {
            return 0.0;
        }

        return PriceLists::unitPrice($list, $productId, $quantity, PlanCatalog::pricesIncludeTax(), self::rate($list, $taxCategoryId));
    }

    /**
     * Gli scaglioni del prodotto, prezzi nella base IVA del catalogo.
     *
     * @return list<array{min_quantity: float, price: float}>
     */
    public static function tiers(?array $list, int $productId, int $taxCategoryId): array
    {
        $tiers = [];

        foreach ((array) ($list['items'][$productId] ?? []) as $tier) {
            $min = (float) ($tier['min_quantity'] ?? 0);
            $tiers[] = ['min_quantity' => $min, 'price' => self::price($list, $productId, $taxCategoryId, max(1.0, $min))];
        }

        usort($tiers, static fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);

        return $tiers;
    }

    public static function reset(): void
    {
        self::$loaded = false;
        self::$list = null;
        self::$customer = ['country' => 'IT', 'type' => 'private'];
        self::$rates = [];
    }

    /** L'aliquota serve solo se listino e catalogo hanno basi IVA diverse. */
    private static function rate(array $list, int $taxCategoryId): float
    {
        if ((bool) ($list['prices_include_tax'] ?? true) === PlanCatalog::pricesIncludeTax()) {
            return 0.0;
        }

        if (!isset(self::$rates[$taxCategoryId])) {
            try {
                $taxId = TaxResolver::resolve(
                    TaxRule::active(),
                    self::$customer['country'],
                    self::$customer['type'],
                    $taxCategoryId,
                    (int) (Setting::current()['fallback_tax_id'] ?? 0)
                );
                $tax = $taxId > 0 ? Tax::findById($taxId) : null;
                self::$rates[$taxCategoryId] = is_array($tax) ? (float) ($tax['rate'] ?? 0) : 0.0;
            } catch (Throwable) {
                self::$rates[$taxCategoryId] = 0.0;
            }
        }

        return self::$rates[$taxCategoryId];
    }
}
