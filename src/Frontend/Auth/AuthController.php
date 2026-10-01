<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Auth;

use Wonder\App\Credentials;
use Wonder\App\Security\RecaptchaGuard;
use Wonder\Auth\Federated\Bridge\LegacySessionLoginAdapter;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\Federated\FederatedLoginService;
use Wonder\Auth\Federated\GoogleIdTokenVerifier;
use Wonder\Auth\Impersonation;
use Wonder\Auth\OneTimeToken;
use Wonder\Auth\PasswordReset;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
use Wonder\Plugin\Ecommerce\Support\SafeRedirect;
use Wonder\View\View;

final class AuthController
{
    public static function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'login' => self::login(),
            'logout' => self::logout(),
            'signup.request' => self::signupRequest(),
            'signup.completion' => self::signupCompletion(),
            'email.sent' => self::render('email-sent'),
            'email.verify' => self::verifyEmail(),
            'password.recovery' => self::passwordRecovery(),
            'password.restore' => self::passwordRestore(),
            'federated' => self::federated((string) ($parameters['provider'] ?? '')),
            'impersonation.start' => self::startImpersonation(),
            'impersonation.stop' => self::stopImpersonation(),
            default => self::notFound(),
        };
    }

    private static function login(): void
    {
        global $ALERT;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            if (self::verifyRecaptcha('ecommerce_login')) {
                $users = new EcommerceUserAccountGateway();
                $user = $users->findUserByEmail((string) ($_POST['email'] ?? ''));
                $provider = is_array($user) && !$users->hasLocalPassword((int) ($user['id'] ?? 0))
                    ? self::federatedProviderForUser((int) ($user['id'] ?? 0))
                    : null;

                if ($provider !== null) {
                    self::render('login', [
                        'alert' => $ALERT ?? null,
                        'federated_error' => 'use_federated_login_'.$provider,
                    ]);
                    return;
                }

                if (\authenticateUserLogin($_POST['email'] ?? '', $_POST['password'] ?? '', 'frontend', 'client')) {
                    self::redirect(SafeRedirect::fromRequest($_POST['continue'] ?? '', '/account/'));
                }
            }
        }

        self::render('login', ['alert' => $ALERT ?? null]);
    }

    private static function logout(): void
    {
        self::requireCsrf();
        \logoutUser('frontend');
        self::redirect(self::route('ecommerce.auth.login'));
    }

    private static function signupRequest(): void
    {
        global $ALERT, $PAGE;
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            if (!self::verifyRecaptcha('ecommerce_signup_request')) {
                self::render('signup-request', ['errors' => [], 'alert' => $ALERT ?? null]);
                return;
            }

            $errors = AuthValidator::signupRequest($_POST);

            if ($errors === []) {
                $continue = SafeRedirect::fromRequest($_POST['continue'] ?? '', '/account/');
                if (isset($PAGE) && is_object($PAGE)) {
                    $PAGE->redirectBase64 = base64_encode($continue);
                }
                $payload = array_merge($_POST, [
                    'area' => 'frontend',
                    'authority' => 'client',
                    'active' => 'true',
                    '_ecommerce_signup_request' => true,
                    'consent_surface' => 'ecommerce_signup',
                ]);
                $created = \user($payload);

                if (empty($ALERT) && (int) ($created->user->id ?? 0) > 0) {
                    self::redirect(self::route('ecommerce.auth.email.sent'));
                }
            }
        }

        self::render('signup-request', ['errors' => $errors, 'alert' => $ALERT ?? null]);
    }

    private static function verifyEmail(): void
    {
        $verified = \confirmUserVerificationToken((string) ($_GET['token'] ?? ''));

        if (!($verified->success ?? false) || (int) ($verified->user_id ?? 0) <= 0) {
            self::render('message', ['message_key' => 'auth.email.invalid']);
            return;
        }

        $userId = (int) $verified->user_id;
        CustomerAccount::linkContact($userId);
        $continue = SafeRedirect::fromRequest($verified->redirect_url ?? '', '/account/');
        $token = (new OneTimeToken(
            'ecommerce_signup_completion',
            (int) Ecommerce::config('auth.completion_token_ttl', 86400)
        ))->issue($userId, null, $continue);

        self::redirect(self::route('ecommerce.auth.signup.completion').'?token='.rawurlencode($token->token));
    }

    private static function signupCompletion(): void
    {
        global $ALERT;
        $tokenValue = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
        $tokens = new OneTimeToken('ecommerce_signup_completion', (int) Ecommerce::config('auth.completion_token_ttl', 86400));
        $record = $tokens->inspect($tokenValue);

        if ($record === null) {
            self::render('message', ['message_key' => 'auth.signup.token_invalid']);
            return;
        }

        $federatedProvider = trim((string) ($record->metadata['federated_provider'] ?? ''));
        $isFederated = $federatedProvider !== '';
        $errors = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            if (!self::verifyRecaptcha('ecommerce_signup_completion')) {
                self::render('signup-completion', [
                    'token' => $tokenValue,
                    'email' => (string) (\infoUser($record->subject_user_id, 'id')->email ?? ''),
                    'errors' => [],
                    'alert' => $ALERT ?? null,
                    'password_required' => !$isFederated,
                ]);
                return;
            }
            $errors = AuthValidator::completion($_POST, !$isFederated);
            $phone = AuthValidator::canonicalPhone($_POST);

            if ($errors === [] && !\unique($phone, 'user', 'phone', $record->subject_user_id)) {
                $errors['phone'] = 'not_unique';
            }

            if ($errors === []) {
                $updated = \user(array_merge($_POST, [
                    'phone' => $phone,
                    '_ecommerce_contact_phone' => (string) ($_POST['phone'] ?? ''),
                    'area' => 'frontend',
                    'authority' => 'client',
                    '_ecommerce_signup_completion' => true,
                    '_ecommerce_federated_completion' => $isFederated,
                    '_ecommerce_link_contact' => true,
                ]), $record->subject_user_id);

                if (empty($ALERT) && ($updated->user->exists ?? false) && $tokens->consume($tokenValue) !== null) {
                    $loggedIn = $isFederated
                        ? (new LegacySessionLoginAdapter())->loginUser((int) $updated->user->id, 'frontend', ['provider' => $federatedProvider])
                        : \authenticateUser('email', $updated->user->email, $_POST['password'], 'frontend', 'client');

                    if ($loggedIn) {
                        self::redirect(SafeRedirect::fromRequest($record->continue_url, '/account/'));
                    }
                }
            }
        }

        $user = \infoUser($record->subject_user_id, 'id');
        self::render('signup-completion', [
            'token' => $tokenValue,
            'email' => (string) ($user->email ?? ''),
            'errors' => $errors,
            'alert' => $ALERT ?? null,
            'password_required' => !$isFederated,
        ]);
    }

    private static function passwordRecovery(): void
    {
        global $ALERT;
        $sent = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            if (!self::verifyRecaptcha('ecommerce_password_recovery')) {
                self::render('password-recovery', ['sent' => false, 'alert' => $ALERT ?? null]);
                return;
            }
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $user = \infoUser($email, 'email');

            if (($user->exists ?? false) && in_array('frontend', (array) ($user->area ?? []), true)) {
                $issued = (new PasswordReset((int) Ecommerce::config('auth.password_reset_ttl', 1800)))
                    ->issueForUser((int) $user->id, self::route('ecommerce.auth.login'));
                $url = self::absolute(self::route('ecommerce.auth.password.restore').'?token='.rawurlencode($issued->token));
                $from = (string) ($GLOBALS['SOCIETY']->email ?? '');
                \sendMail($from, $email, (string) __t('ecommerce.auth.email.reset_subject'), (string) __t('ecommerce.auth.email.reset_body', ['url' => $url]));
            }

            $sent = true;
        }

        self::render('password-recovery', ['sent' => $sent, 'alert' => $ALERT ?? null]);
    }

    private static function passwordRestore(): void
    {
        global $ALERT;
        $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
        $service = new PasswordReset((int) Ecommerce::config('auth.password_reset_ttl', 1800));
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::requireCsrf();
            if (!self::verifyRecaptcha('ecommerce_password_restore')) {
                self::render('password-restore', [
                    'token' => $token,
                    'errors' => [],
                    'alert' => $ALERT ?? null,
                ]);
                return;
            }
            $errors = AuthValidator::completion([
                'phone_prefix' => '+1',
                'phone' => '000000',
                'password' => $_POST['password'] ?? '',
                'password_confirmation' => $_POST['password_confirmation'] ?? '',
            ]);
            unset($errors['phone']);

            if ($errors === []) {
                $result = $service->reset($token, (string) $_POST['password']);
                if ($result->success ?? false) {
                    self::redirect(self::route('ecommerce.auth.login').'?reset=1');
                }
                $errors['token'] = 'invalid';
            }
        }

        self::render('password-restore', ['token' => $token, 'errors' => $errors, 'alert' => $ALERT ?? null]);
    }

    private static function federated(string $provider): void
    {
        $provider = strtolower(trim($provider));
        $surface = ($_POST['auth_surface'] ?? '') === 'signup' ? 'signup-request' : 'login';
        $api = Credentials::api();
        $nonce = (string) ($_SESSION['ecommerce_oidc_nonce'][$provider] ?? '');
        $idToken = (string) ($_POST['credential'] ?? $_POST['id_token'] ?? '');

        try {
            self::requireCsrf();
            $verifier = match ($provider) {
                'google' => new GoogleIdTokenVerifier((string) ($api->google_oauth_client_id ?? ''), $nonce ?: null),
                default => throw new \RuntimeException('provider_not_supported'),
            };
            $identity = $verifier->verify($idToken);
            $users = new EcommerceUserAccountGateway();
            $identities = new FederatedIdentityRepository();
            $linkedIdentity = $identities->findByProviderIdentity($identity->provider, $identity->providerUserId);
            $existing = $users->findUserByEmail($identity->email);
            $newAccount = $linkedIdentity === null && $existing === null;

            if ($linkedIdentity === null && !$identity->emailVerified) {
                throw new \RuntimeException('federated_email_not_verified');
            }

            $result = (new FederatedLoginService($users, $identities))
                ->authenticate($identity, 'frontend', ['client']);

            if (!$result->success || !$result->userId) {
                throw new \RuntimeException($result->reason ?: 'federated_login_failed');
            }

            if ($newAccount) {
                CustomerAccount::linkContact($result->userId);
            }

            $user = $users->findUserById($result->userId);
            if (empty($user['phone'])) {
                $completion = (new OneTimeToken('ecommerce_signup_completion', (int) Ecommerce::config('auth.completion_token_ttl', 86400)))
                    ->issue(
                        $result->userId,
                        null,
                        SafeRedirect::fromRequest($_POST['continue'] ?? '', '/account/'),
                        ['federated_provider' => $provider]
                    );
                self::redirect(self::route('ecommerce.auth.signup.completion').'?token='.rawurlencode($completion->token));
            }

            (new LegacySessionLoginAdapter())->loginUser($result->userId, 'frontend', ['provider' => $provider]);
            self::redirect(SafeRedirect::fromRequest($_POST['continue'] ?? '', '/account/'));
        } catch (\Throwable $exception) {
            self::render($surface, ['federated_error' => $exception->getMessage()]);
        }
    }

    private static function startImpersonation(): void
    {
        $service = self::impersonation();
        $result = $service->start((string) ($_GET['token'] ?? ''));
        self::redirect(($result->success ?? false)
            ? SafeRedirect::fromRequest($result->continue_url, '/')
            : self::route('ecommerce.auth.login'));
    }

    private static function federatedProviderForUser(int $userId): ?string
    {
        foreach ((new FederatedIdentityRepository())->findByUserId($userId) as $identity) {
            $provider = strtolower(trim((string) ($identity['provider'] ?? '')));
            if ($provider !== '') {
                return $provider;
            }
        }

        return null;
    }

    private static function stopImpersonation(): void
    {
        $result = self::impersonation()->stop((string) ($_POST['csrf_token'] ?? ''));
        self::redirect(($result->success ?? false)
            ? SafeRedirect::fromRequest($result->return_url, '/backend/')
            : '/');
    }

    private static function impersonation(): Impersonation
    {
        return new Impersonation(
            (array) Ecommerce::config('impersonation.actor_authorities', ['admin']),
            (int) Ecommerce::config('impersonation.token_ttl', 120),
        );
    }

    private static function render(string $page, array $data = []): void
    {
        self::configureSeo($page, $data);
        $nonce = bin2hex(random_bytes(24));
        $api = Credentials::api();
        $_SESSION['ecommerce_oidc_nonce']['google'] = $nonce;

        View::make(Ecommerce::viewPath('pages/auth/'.$page.'.php'), $data + [
            'alert' => null,
            'errors' => [],
            'federated_error' => null,
            'csrf_token' => AuthSession::csrfToken(),
            'oidc_nonce' => $nonce,
            'google_client_id' => Ecommerce::config('auth.federated.google', true) ? (string) ($api->google_oauth_client_id ?? '') : '',
            'values' => $_POST,
        ])->render();
    }

    private static function verifyRecaptcha(string $action): bool
    {
        global $ALERT;

        try {
            RecaptchaGuard::for($action)
                ->withService('ecommerce-auth')
                ->withLogAction($action)
                ->verify();

            return true;
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() !== RecaptchaGuard::ERROR_CODE) {
                throw $exception;
            }

            $ALERT = RecaptchaGuard::ERROR_CODE;

            return false;
        }
    }

    private static function configureSeo(string $page, array $data): void
    {
        global $SEO;

        $definition = match ($page) {
            'login' => ['ecommerce.auth.login.title', 'ecommerce.auth.seo.login', 'ecommerce.auth.login'],
            'signup-request' => ['ecommerce.auth.signup.title', 'ecommerce.auth.seo.signup', 'ecommerce.auth.signup.request'],
            'signup-completion' => ['ecommerce.auth.signup.complete_title', 'ecommerce.auth.seo.signup_completion', 'ecommerce.auth.signup.completion'],
            'email-sent' => ['ecommerce.auth.email.sent_title', 'ecommerce.auth.seo.email_sent', 'ecommerce.auth.email.sent'],
            'password-recovery' => ['ecommerce.auth.recovery.title', 'ecommerce.auth.seo.password_recovery', 'ecommerce.auth.password.recovery'],
            'password-restore' => ['ecommerce.auth.restore.title', 'ecommerce.auth.seo.password_restore', 'ecommerce.auth.password.restore'],
            default => ['ecommerce.auth.message.title', 'ecommerce.auth.seo.message', 'ecommerce.auth.login'],
        };

        $SEO->title = (string) __t($definition[0]);
        $SEO->description = (string) __t($definition[1]);
        $SEO->url = self::route($definition[2]);
        $SEO->breadcrumb = [];
        $SEO->robots = 'NOINDEX,FOLLOW';
    }

    private static function requireCsrf(): void
    {
        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(419);
            exit('CSRF token invalid');
        }
    }

    private static function route(string $name, array $parameters = []): string
    {
        return \__r($name, $parameters) ?: '/';
    }

    private static function absolute(string $path): string
    {
        return rtrim((string) ($_ENV['APP_URL'] ?? ''), '/').'/'.ltrim($path, '/');
    }

    private static function redirect(string $url): never
    {
        header('Location: '.$url);
        exit;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        exit;
    }
}
