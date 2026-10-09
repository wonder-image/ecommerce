<?php // tests/harness.php
declare(strict_types=1);

// I test girano sul database del sito di prova, con indirizzi veri:
// `sendMail()` del core risponde sì e non spedisce niente.
if (!defined('WONDER_NO_MAIL')) {
    define('WONDER_NO_MAIL', true);
}

// Nessuna chiamata a Stripe, mai: nel sito di prova ci sono le chiavi test vere.
// Il client senza rete è quello del gestionale, ricavato dal pacchetto installato.
// Se manca, ogni richiesta a Stripe viene bloccata e la suite esce rossa.
require_once __DIR__.'/gestionale-supporto.php';

if (class_exists(\Stripe\ApiRequestor::class)) {
    $senzaRete = gestionaleSupporto('StripeSenzaRete.php');

    if ($senzaRete !== null) {
        require_once $senzaRete;
    } else {
        $GLOBALS['__stripeBloccata'] = [];

        \Stripe\ApiRequestor::setHttpClient(new class implements \Stripe\HttpClient\ClientInterface {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $richiesta = strtoupper((string) $method).' '.(string) parse_url((string) $absUrl, PHP_URL_PATH);
                $GLOBALS['__stripeBloccata'][] = $richiesta;

                throw new \LogicException('Stripe bloccato: tests/supporto/StripeSenzaRete.php del gestionale non c\'è, e un test non deve mai uscire in rete ('.$richiesta.').');
            }
        });

        // Anche se il codice sotto prova inghiotte l'eccezione, la suite non passa.
        register_shutdown_function(static function (): void {
            if (($GLOBALS['__stripeBloccata'] ?? []) !== []) {
                echo "\n  ✗ Stripe bloccato: client senza rete del gestionale assente, richieste: ".implode(', ', $GLOBALS['__stripeBloccata'])."\n";
                exit(1);
            }
        });
    }
}

$GLOBALS['__tests'] = 0;
$GLOBALS['__failures'] = 0;

function check(string $name, callable $fn): void
{
    $GLOBALS['__tests']++;
    try {
        $ok = $fn();
        if ($ok === false) {
            $GLOBALS['__failures']++;
            echo "  ✗ {$name}\n";
        } else {
            echo "  ✓ {$name}\n";
        }
    } catch (\Throwable $e) {
        $GLOBALS['__failures']++;
        echo "  ✗ {$name} — {$e->getMessage()}\n";
    }
}

/**
 * Una cartella fotografata prima del test e rimessa com'era dopo.
 *
 * I file non stanno dentro la transazione. Un test d'integrazione annulla il
 * database e il database torna indietro; il disco no: quello che il codice
 * sotto prova ha cancellato resta cancellato, e le righe tornate indietro
 * puntano a file che non ci sono più. È già costato due volte le foto dei
 * dati di prova.
 *
 * Il ripristino è registrato anche come `register_shutdown_function`: così
 * vale pure quando il test muore a metà — un'eccezione non catturata, un
 * errore fatale, un `exit()` — che è esattamente il caso in cui serve. Non
 * copre il processo ucciso di forza (`kill -9`), che non lascia girare niente.
 *
 * Attenzione: rimettere com'era vuol dire anche **togliere** i file nati
 * durante il test. Se qualcun altro scrive in quella cartella nello stesso
 * momento, i suoi file se ne vanno con gli altri.
 */
final class Istantanea
{
    private bool $fatta = false;

    private function __construct(
        private readonly string $cartella,
        private readonly string $copia,
    ) {
    }

    public static function di(string $cartella): self
    {
        $cartella = rtrim($cartella, '/').'/';
        $copia = sys_get_temp_dir().'/wi-istantanea-'.getmypid().'-'.substr(md5($cartella), 0, 8);
        $istantanea = new self($cartella, $copia);

        if (!is_dir($cartella)) {
            return $istantanea;
        }

        if (!is_dir($copia)) {
            mkdir($copia, 0777, true);
        }

        foreach (glob($cartella.'*') ?: [] as $file) {
            if (is_file($file)) {
                copy($file, $copia.'/'.basename($file));
            }
        }

        // La rete di sicurezza: se il test non ci arriva, ci arriva la fine
        // del processo. Chiamarla due volte non fa niente.
        register_shutdown_function(static fn () => $istantanea->ripristina());

        return $istantanea;
    }

    /** Rimette la cartella com'era. Si può chiamare quante volte si vuole. */
    public function ripristina(): void
    {
        if ($this->fatta || !is_dir($this->copia)) {
            return;
        }

        $this->fatta = true;

        if (!is_dir($this->cartella)) {
            mkdir($this->cartella, 0777, true);
        }

        $salvati = [];

        foreach (glob($this->copia.'/*') ?: [] as $file) {
            $nome = basename($file);
            $salvati[$nome] = true;

            if (!is_file($this->cartella.$nome)) {
                copy($file, $this->cartella.$nome);
            }

            unlink($file);
        }

        // Quello che è nato durante il test se ne va con il test: le righe che
        // lo nominavano sono state annullate.
        foreach (glob($this->cartella.'*') ?: [] as $file) {
            if (is_file($file) && !isset($salvati[basename($file)])) {
                unlink($file);
            }
        }

        rmdir($this->copia);
    }
}

function summary(): void
{
    $t = $GLOBALS['__tests'];
    $f = $GLOBALS['__failures'];
    echo "\n{$t} test, {$f} falliti\n";
    exit($f === 0 ? 0 : 1);
}
