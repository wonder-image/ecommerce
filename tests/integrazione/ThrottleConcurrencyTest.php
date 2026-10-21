<?php
/**
 * php tests/integrazione/ThrottleConcurrencyTest.php
 *
 * Throttle::attempt() sulla tabella vera: N processi in parallelo sulla stessa
 * chiave, ne passano al massimo `limit`. Le classi dell'ecommerce sono quelle
 * di questo pacchetto, non quelle installate nel sito.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');
const PACCHETTO = __DIR__.'/../..';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Wonder\\Plugin\\Ecommerce\\';
    if (str_starts_with($class, $prefix)) {
        $file = PACCHETTO.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Ecommerce\Models\Throttle as ThrottleModel;
use Wonder\Plugin\Ecommerce\Support\Throttle;
use Wonder\Sql\Connection;

// Figlio: un solo tentativo, l'esito su stdout.
if (($argv[1] ?? '') === '--figlio') {
    echo Throttle::attempt('test_concorrenza', (string) $argv[2], (int) $argv[3], 60) ? '1' : '0';
    exit(0);
}

require __DIR__.'/../harness.php';

$connessione = Connection::Connect('main');
if (!$connessione->query("SHOW TABLES LIKE 'ecommerce_throttle'")->num_rows) {
    ThrottleModel::createTable();
}

$chiave = 'k'.bin2hex(random_bytes(6));
$limite = 3;
$processi = 12;

$aperti = [];
for ($i = 0; $i < $processi; $i++) {
    $aperti[] = proc_open([PHP_BINARY, __FILE__, '--figlio', $chiave, (string) $limite], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
    $tubi[] = $pipe;
}
$esiti = [];
foreach ($aperti as $i => $processo) {
    $esiti[] = trim((string) stream_get_contents($tubi[$i][1]));
    proc_close($processo);
}

check("{$processi} tentativi in parallelo: ne passano al massimo {$limite}", fn () =>
    count($esiti) === $processi && substr_count(implode('', $esiti), '1') <= $limite && substr_count(implode('', $esiti), '1') >= 1);

check('la riga conta tutti i tentativi della finestra', function () use ($connessione, $chiave, $processi): bool {
    $select = $connessione->prepare('SELECT hits FROM ecommerce_throttle WHERE throttle_key = ?');
    $select->execute([hash('sha256', 'test_concorrenza|'.$chiave)]);

    return (int) ($select->get_result()->fetch_assoc()['hits'] ?? 0) === $processi;
});

check('finestra scaduta: il conto riparte da 1', function () use ($connessione, $chiave): bool {
    $update = $connessione->prepare('UPDATE ecommerce_throttle SET window_start = window_start - 120 WHERE throttle_key = ?');
    $update->execute([hash('sha256', 'test_concorrenza|'.$chiave)]);

    return Throttle::attempt('test_concorrenza', $chiave, 3, 60);
});

$delete = $connessione->prepare('DELETE FROM ecommerce_throttle WHERE throttle_key = ?');
$delete->execute([hash('sha256', 'test_concorrenza|'.$chiave)]);

summary();
