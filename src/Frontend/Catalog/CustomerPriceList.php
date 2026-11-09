<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutCompany;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceLists;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;

/**
 * I listini che valgono per chi guarda il negozio, letti una volta per richiesta:
 * prima quello del cliente loggato (gestionale#78), poi quello del canale
 * `online` (gestionale#162), come nel carrello.
 *
 * Lo chiede solo chi mostra prezzi in negozio (scheda, card, ricerca). Feed,
 * llms.txt e dati strutturati restano sul prezzo di catalogo: i feed hanno il
 * listino del loro canale nel gestionale.
 */
final class CustomerPriceList
{
    public const CHANNEL = 'online';

    private static bool $loaded = false;

    /** @var array{lists: list<array<string, mixed>>}|null */
    private static ?array $list = null;

    /** @var array{country: string, type: string} */
    private static array $customer = ['country' => 'IT', 'type' => 'private'];

    /** @var array<int, float> aliquota per categoria IVA */
    private static array $rates = [];

    /** @return array{lists: list<array<string, mixed>>}|null null se oggi non vale nessun listino */
    public static function current(): ?array
    {
        if (self::$loaded) {
            return self::$list;
        }

        self::$loaded = true;

        try {
            $contactId = CartSession::customerId();
            // Comprando per un'azienda vale il suo listino (gestionale#77).
            $companyId = CheckoutCompany::ofOrder(['company_id' => CartSession::companyId()], $contactId);
            $lists = PriceLists::loadAll(
                PriceLists::candidates(PriceLists::forDocument(['company_id' => $companyId, 'customer_id' => $contactId]), self::CHANNEL),
                date('Y-m-d H:i:s'),
                (string) Ecommerce::config('catalog.currency', 'EUR')
            );

            if ($lists === []) {
                return null;
            }

            self::$list = ['lists' => $lists];

            // Paese e tipo come li legge il carrello dalla fatturazione.
            $holder = $companyId > 0 ? Company::findById($companyId) : ($contactId > 0 ? Contact::findById($contactId) : null);
            $holder = is_array($holder) ? $holder : [];
            self::$customer = [
                'country' => strtoupper(trim((string) ($holder['country'] ?? ''))) ?: 'IT',
                'type' => $companyId > 0 ? 'business' : 'private',
            ];
        } catch (Throwable) {
            self::$list = null;
        }

        return self::$list;
    }

    /**
     * Il prezzo unitario a listino nella base IVA del catalogo; 0 se nessun listino
     * ha il prodotto. `$basePrice` è il prezzo pieno del prodotto, da cui parte
     * la regola in percentuale.
     */
    public static function price(?array $list, int $productId, int $taxCategoryId, float $quantity = 1.0, float $basePrice = 0.0): float
    {
        $lists = self::lists($list);

        if ($lists === []) {
            return 0.0;
        }

        // L'aliquota serve solo al listino con base IVA diversa dal catalogo.
        $found = self::first($lists, $productId);
        $rate = $found !== null ? self::rate($found, $taxCategoryId) : 0.0;

        return PriceLists::resolve($lists, $productId, $quantity, $basePrice, PlanCatalog::pricesIncludeTax(), $rate)['price'];
    }

    /**
     * Gli scaglioni del prodotto, prezzi nella base IVA del catalogo: quelli del
     * primo listino che lo trova. Con la regola in percentuale non ce ne sono.
     *
     * @return list<array{min_quantity: float, price: float}>
     */
    public static function tiers(?array $list, int $productId, int $taxCategoryId): array
    {
        $found = self::first(self::lists($list), $productId);

        if ($found === null || !isset($found['items'][$productId])) {
            return [];
        }

        $tiers = [];
        $rate = self::rate($found, $taxCategoryId);

        foreach ((array) $found['items'][$productId] as $tier) {
            $min = (float) ($tier['min_quantity'] ?? 0);
            $tiers[] = ['min_quantity' => $min, 'price' => PriceLists::unitPrice($found, $productId, max(1.0, $min), PlanCatalog::pricesIncludeTax(), $rate)];
        }

        usort($tiers, static fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);

        return $tiers;
    }

    /** @return list<array<string, mixed>> */
    private static function lists(?array $list): array
    {
        return array_values(array_filter((array) ($list['lists'] ?? []), 'is_array'));
    }

    /**
     * Il primo listino che trova il prodotto: con una sua riga o con la regola
     * in percentuale, che lo trova sempre.
     *
     * @param list<array<string, mixed>> $lists
     */
    private static function first(array $lists, int $productId): ?array
    {
        foreach ($lists as $list) {
            if (isset($list['items'][$productId]) || (($list['rule'] ?? 'none') !== 'none' && (float) ($list['percent'] ?? 0) > 0)) {
                return $list;
            }
        }

        return null;
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
