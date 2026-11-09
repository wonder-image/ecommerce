<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/** Un'azienda nella scheda «La mia azienda»: fatturazione e persone, come righe del pannello (`frontend.account.row`). */
final class AccountCompany
{
    /**
     * @param array<string, mixed> $company una riga di `companies`
     * @return array{id: int, name: string, billing: array<string, mixed>, people: list<array<string, mixed>>}
     */
    public static function present(array $company, string $modal): array
    {
        $id = (int) ($company['id'] ?? 0);

        return [
            'id' => $id,
            'name' => trim((string) ($company['business_name'] ?? '')),
            'billing' => self::billing($company, $modal),
            'people' => self::people(ContactRelations::peopleOf($id)),
        ];
    }

    /** La fatturazione in una riga, col pulsante che apre il modal. @param array<string, mixed> $company */
    public static function billing(array $company, string $modal): array
    {
        $text = static fn (string $key): string => trim((string) ($company[$key] ?? ''));
        $value = static fn (string $text): string => $text !== '' ? $text : '—';
        $address = implode(', ', array_filter([
            trim($text('street').' '.$text('number')),
            trim($text('cap').' '.$text('city').' '.$text('province')),
            $text('country'),
        ]));
        $tax = implode(' · ', array_filter([$text('pi'), $text('cf') !== $text('pi') ? $text('cf') : '']));
        $delivery = implode(' · ', array_filter([$text('sdi'), $text('pec')]));

        return [
            'key' => 'company-'.(string) ($company['id'] ?? ''),
            'columns' => [
                ['label' => (string) __t('ecommerce.account.company.tax'), 'value' => $value($tax)],
                ['label' => (string) __t('ecommerce.account.company.address'), 'value' => $value($address)],
                ['label' => (string) __t('ecommerce.account.company.delivery'), 'value' => $value($delivery)],
            ],
            'action' => ['label' => (string) __t('ecommerce.account.company.edit'), 'href' => '', 'modal' => $modal, 'icon' => 'bi bi-pencil', 'disabled' => false, 'hint' => ''],
        ];
    }

    /**
     * Le persone legate all'azienda: nome, ruolo e accesso. Si gestiscono dal backend.
     *
     * @param list<array<string, mixed>> $links da `ContactRelations::peopleOf()`
     * @return list<array<string, mixed>>
     */
    public static function people(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            $person = (array) ($link['contact'] ?? []);
            if (($link['deleted'] ?? 'false') === 'true' || ($person['deleted'] ?? 'false') === 'true') {
                continue;
            }
            $access = (string) ($link['access'] ?? ContactRelation::ACCESS_NONE);
            $access = in_array($access, ContactRelation::ACCESS, true) ? $access : ContactRelation::ACCESS_NONE;
            $out[] = [
                'key' => 'person-'.(string) ($link['id'] ?? ''),
                'columns' => [
                    ['label' => (string) __t('ecommerce.account.company.person'), 'value' => trim((string) ($person['name'] ?? '').' '.(string) ($person['surname'] ?? '')) ?: '—'],
                    ['label' => (string) __t('ecommerce.account.company.job_title'), 'value' => trim((string) ($link['job_title'] ?? '')) ?: '—'],
                    ['label' => (string) __t('ecommerce.account.company.access'), 'value' => (string) __t('ecommerce.account.company.access_'.$access)],
                ],
                'action' => [],
            ];
        }

        return $out;
    }
}
