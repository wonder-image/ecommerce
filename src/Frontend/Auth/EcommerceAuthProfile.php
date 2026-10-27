<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Auth;

use Wonder\Plugin\CustomerAccount\Auth\CustomerAuthProfile;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Support\Orders\OrderOwner;

/** Telefono richiesto di default; gli altri campi della registrazione dalla config di customer-account. */
class EcommerceAuthProfile extends CustomerAuthProfile
{
    public function key(): string { return 'ecommerce'; }
    public function routePrefix(): string { return 'ecommerce.auth'; }
    public function documents(): array { return ['privacy_policy', 'terms_conditions']; }
    public function completionTtl(): int { return (int) Ecommerce::config('auth.completion_token_ttl', 86400); }
    public function resetTtl(): int { return (int) Ecommerce::config('auth.password_reset_ttl', 1800); }
    public function googleEnabled(): bool { return (bool) Ecommerce::config('auth.federated.google', true); }
    public function impersonationEnabled(): bool { return (bool) Ecommerce::config('impersonation.enabled', true); }
    public function impersonationAuthorities(): array { return Ecommerce::impersonationAuthorities(); }
    public function impersonationTtl(): int { return (int) Ecommerce::config('impersonation.token_ttl', 120); }

    protected function signupDefaults(): array { return ['phone' => 'required']; }

    /** Layout del modulo: font, stili e slot `auth.*` attorno a quello del core. */
    public function layout(array $data = []): void
    {
        Ecommerce::layout('auth', ['auth_layout' => $data + ['auth_profile' => $this]]);
    }

    /** La scheda la collega customer-account; il cellulare del modulo ecommerce, se c'è, vale come telefono. */
    public function afterUserSaved(int $userId, string $surface, array $input): void
    {
        if (isset($input['_ecommerce_contact_phone'])) {
            $input['phone'] = $input['_ecommerce_contact_phone'];
        }
        parent::afterUserSaved($userId, $surface, $input);
    }

    protected function afterContactLinked(int $userId, int $contactId, string $surface, array $input): void
    {
        // L'email ora è provata: gli ordini fatti con lei prima dell'account sono suoi.
        OrderOwner::claim($userId, $contactId, (string) (infoUser($userId, 'id')->email ?? ''));
    }
}
