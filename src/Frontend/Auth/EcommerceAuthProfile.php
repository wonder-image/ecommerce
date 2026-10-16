<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Auth;

use Wonder\Plugin\CustomerAccount\Auth\CustomerAuthProfile;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
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
    public function impersonationAuthorities(): array { return (array) Ecommerce::config('impersonation.actor_authorities', ['admin']); }
    public function impersonationTtl(): int { return (int) Ecommerce::config('impersonation.token_ttl', 120); }

    protected function signupDefaults(): array { return ['phone' => 'required']; }

    /** Collega la scheda (non il genitore, che la collegherebbe due volte), poi i campi della registrazione. */
    public function afterUserSaved(int $userId, string $surface, array $input): void
    {
        if (!in_array($surface, ['email.verify', 'signup-completion', 'federated'], true)) {
            return;
        }
        $result = CustomerAccount::linkContact($userId, $input);
        if (!($result->success ?? false)) {
            throw new \RuntimeException('contact_write_failed');
        }
        // L'email ora è provata: gli ordini fatti con lei prima dell'account sono suoi.
        OrderOwner::claim($userId, (int) ($result->contact_id ?? 0), (string) (infoUser($userId, 'id')->email ?? ''));
        if ($surface === 'signup-completion') {
            $this->saveSignupFields($userId, $input, (int) ($result->contact_id ?? 0));
        }
    }
}
