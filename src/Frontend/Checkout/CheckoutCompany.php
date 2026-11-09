<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * «Acquista come» (funzionalità `company_accounts`): la persona entrata compra
 * per sé o per un'azienda a cui è legata con accesso `buyer` o `admin`.
 *
 * Con un'azienda l'ordine porta `company_id`, la persona resta `customer_id`
 * (il referente, col nome in `referent_name`) e la fatturazione è quella
 * dell'azienda, non quella del modulo: la cambia un suo amministratore nella
 * scheda «La mia azienda». Listino e condizioni li legge il gestionale da
 * `company_id`.
 */
final class CheckoutCompany
{
    public const BUYING = [ContactRelation::ACCESS_BUYER, ContactRelation::ACCESS_ADMIN];

    /** @var array<int, list<array<string, mixed>>> per richiesta */
    private static array $options = [];

    public static function enabled(): bool
    {
        return Gestionale::feature('company_accounts') && ContactRelations::enabled();
    }

    /** La scheda di chi è entrato; 0 per l'ospite. */
    public static function contactId(): int
    {
        return CartSession::authenticated() ? CartSession::customerId() : 0;
    }

    /**
     * Le aziende per cui la persona può comprare, vive e nell'ordine dei legami.
     *
     * @return list<array{id: int, name: string, access: string, company: array<string, mixed>}>
     */
    public static function options(int $contactId): array
    {
        if ($contactId <= 0 || !self::enabled()) {
            return [];
        }

        if (isset(self::$options[$contactId])) {
            return self::$options[$contactId];
        }

        $out = [];
        foreach (ContactRelations::companiesOf($contactId, self::BUYING) as $row) {
            $company = (array) ($row['company'] ?? []);
            $id = (int) ($company['id'] ?? 0);
            if ($id <= 0 || ($row['deleted'] ?? 'false') === 'true' || ($company['deleted'] ?? 'false') === 'true' || ($company['active'] ?? 'true') === 'false' || isset($out[$id])) {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'name' => trim((string) ($company['business_name'] ?? '')),
                'access' => (string) $row['access'],
                'company' => $company,
            ];
        }

        return self::$options[$contactId] = array_values($out);
    }

    /**
     * Le aziende di cui la persona è amministratore: nell'area account ne vede
     * ordini e abbonamenti e ne modifica i dati.
     *
     * @return list<int>
     */
    public static function adminIds(int $contactId): array
    {
        $ids = [];
        foreach (self::options($contactId) as $option) {
            if ($option['access'] === ContactRelation::ACCESS_ADMIN) {
                $ids[] = $option['id'];
            }
        }

        return $ids;
    }

    /** Solo per i test: dimentica le aziende lette. */
    public static function forget(): void
    {
        self::$options = [];
    }

    /**
     * L'azienda scelta, solo se è fra quelle offerte; altrimenti 0 (per sé).
     *
     * @param list<array<string, mixed>>|null $options quelle di `options()` se null
     */
    public static function resolve(int $contactId, mixed $posted, ?array $options = null): int
    {
        $id = is_scalar($posted) && ctype_digit(trim((string) $posted)) ? (int) $posted : 0;
        if ($id <= 0) {
            return 0;
        }

        foreach ($options ?? self::options($contactId) as $option) {
            if ((int) ($option['id'] ?? 0) === $id) {
                return $id;
            }
        }

        return 0;
    }

    /** L'opzione con quell'id, null se non c'è. */
    public static function find(int $contactId, int $companyId): ?array
    {
        foreach (self::options($contactId) as $option) {
            if ($option['id'] === $companyId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Il POST con la scelta decisa: senza la chiave `company_id` (barra rapida,
     * moduli vecchi) resta com'è; con un'azienda non valida vale «per sé».
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function apply(array $post, ?int $contactId = null): array
    {
        if (!array_key_exists('company_id', $post)) {
            return $post;
        }

        if ($contactId === null) {
            // Gli abbonamenti nascono per la persona: con i servizi si compra per sé.
            $contactId = CheckoutSubscriptions::selected() ? 0 : self::contactId();
        }
        $option = self::find($contactId, self::resolve($contactId, $post['company_id']));
        $person = $option === null ? [] : (array) (Contact::findById($contactId) ?: []);

        return self::overlay($post, $option['company'] ?? null, $person);
    }

    /**
     * Il POST con la fatturazione dell'azienda al posto di quella del modulo
     * (anche i campi vuoti: un'azienda senza indirizzo non prende quello della
     * persona); fattura sempre, intestata a società.
     * Nome e cognome della fatturazione sono quelli del referente.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $company null: compra per sé
     * @param array<string, mixed> $person la scheda di chi compra
     * @return array<string, mixed>
     */
    public static function overlay(array $post, ?array $company, array $person): array
    {
        $companyId = (int) ($company['id'] ?? 0);
        if ($company === null || $companyId <= 0) {
            return ['company_id' => 0, 'referent_name' => ''] + $post;
        }

        $post = self::billing($company) + $post;

        $name = trim((string) ($person['name'] ?? ''));
        $surname = trim((string) ($person['surname'] ?? ''));
        foreach (['name' => $name, 'surname' => $surname] as $field => $value) {
            if ($value !== '') {
                $post['billing_'.$field] = $value;
            }
        }

        $post['company_id'] = $companyId;
        $post['referent_name'] = trim($name.' '.$surname);
        $post['invoice'] = '1';
        $post['billing_type'] = 'business';
        // La fatturazione è scritta (quella dell'azienda), non copiata dalla spedizione.
        $post['same_as_shipping'] = '0';

        return $post;
    }

    /**
     * La fatturazione dell'azienda con le chiavi dell'ordine. Il cellulare no:
     * è sempre quello dei contatti del modulo.
     *
     * @param array<string, mixed> $company
     * @return array<string, string>
     */
    public static function billing(array $company): array
    {
        $billing = [];
        foreach (array_keys(Company::billing()->labels()) as $key) {
            if (!in_array($key, ['phone_prefix', 'phone'], true)) {
                $billing['billing_'.$key] = trim((string) ($company[$key] ?? ''));
            }
        }

        return $billing;
    }

    /** La fatturazione dell'azienda in una riga, per la scelta del checkout. */
    public static function summary(array $company): string
    {
        $text = static fn (string $key): string => trim((string) ($company[$key] ?? ''));
        $street = trim($text('street').' '.$text('number'));
        $city = trim($text('cap').' '.$text('city').($text('province') !== '' ? ' ('.$text('province').')' : ''));
        $vat = $text('pi') !== '' ? __t('ecommerce.checkout.company.vat', ['pi' => $text('pi')]) : '';

        return implode(' · ', array_filter([$vat, implode(', ', array_filter([$street, $city]))]));
    }

    /** L'azienda dell'ordine o del carrello, se chi guarda può ancora comprare per lei; altrimenti 0. */
    public static function ofOrder(array $order, ?int $contactId = null): int
    {
        $contactId ??= self::contactId();

        return self::resolve($contactId, $order['company_id'] ?? 0);
    }
}
