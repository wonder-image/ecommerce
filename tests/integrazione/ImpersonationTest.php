<?php
/** php tests/integrazione/ImpersonationTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\App\Models\User\User;
use Wonder\Auth\Impersonation;
use Wonder\Sql\Transaction;

final class AnnullaImpersonation extends RuntimeException {}

$sessionBefore = $_SESSION;

try {
    Transaction::run(static function (): void {
        $suffix = bin2hex(random_bytes(6));
        $actor = User::create([
            'name' => 'Admin',
            'surname' => 'Test',
            'email' => 'impersonation-admin-'.$suffix.'@example.com',
            'username' => 'impersonation-admin-'.$suffix,
            'authority' => json_encode(['administrator'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['backend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $subject = User::create([
            'name' => 'Cliente',
            'surname' => 'Test',
            'email' => 'impersonation-client-'.$suffix.'@example.com',
            'username' => 'impersonation-client-'.$suffix,
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $actorId = (int) ($actor->insert_id ?? 0);
        $subjectId = (int) ($subject->insert_id ?? 0);
        $_SESSION['user_id'] = $actorId;

        $service = new Impersonation(['administrator'], 120);
        $issued = $service->issue($actorId, $subjectId, '/account/', '/backend/', '/account/auth/impersonate/stop/');
        $started = $service->start($issued->token);
        $during = $service->current();
        $stopped = $service->stop($service->csrfToken('stop'));

        $auditQuery = sqlSelect('auth_impersonation_audits', [
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectId,
        ]);
        $auditRows = !($auditQuery->exists ?? false)
            ? []
            : (isset($auditQuery->row['id']) ? [$auditQuery->row] : array_values(array_filter((array) $auditQuery->row, 'is_array')));
        $events = array_column($auditRows, 'event');

        check('l\'impersonificazione conserva attore e soggetto separati', fn () =>
            ($started->success ?? false)
            && ($during->actor_user_id ?? 0) === $actorId
            && ($during->subject_user_id ?? 0) === $subjectId
            && ($started->continue_url ?? '') === '/account/'
        );

        check('lo stop ripristina l\'attore e la destinazione backend', fn () =>
            ($stopped->success ?? false)
            && (int) ($_SESSION['user_id'] ?? 0) === $actorId
            && ($stopped->return_url ?? '') === '/backend/'
            && $service->current() === null
        );

        check('issue, start e stop sono tracciati', fn () =>
            in_array('issued', $events, true)
            && in_array('started', $events, true)
            && in_array('stopped', $events, true)
        );

        throw new AnnullaImpersonation();
    });
} catch (AnnullaImpersonation) {
} finally {
    $_SESSION = $sessionBefore;
}

summary();
