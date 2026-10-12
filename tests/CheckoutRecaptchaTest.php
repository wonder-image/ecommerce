<?php
/** php tests/CheckoutRecaptchaTest.php — la sfida 618 del reCAPTCHA nel checkout: controller e JavaScript. */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);

/** Il corpo di un metodo PHP: dalla firma alla prima graffa chiusa a quattro spazi; vuoto se non c'è. */
$metodo = static function (string $file, string $nome): string {
    $codice = (string) file_get_contents($file);
    $inizio = strpos($codice, "function {$nome}(");
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($codice, "\n    }\n", $inizio);

    return $fine === false ? substr($codice, $inizio) : substr($codice, $inizio, $fine - $inizio);
};

$file = $root.'/src/Frontend/Checkout/CheckoutController.php';
$controller = (string) file_get_contents($file);
$place = $metodo($file, 'place');

check('use della sfida', fn () => str_contains($controller, 'use Wonder\App\Security\RecaptchaChallenge;'));
check('place: la sfida si prende dopo CheckoutRejected e prima di UserError e RuntimeException', fn () =>
    str_contains($place, 'catch (RecaptchaChallenge $e)')
    && strpos($place, 'catch (RecaptchaChallenge $e)') > (int) strpos($place, 'catch (CheckoutRejected $e)')
    && strpos($place, 'catch (RecaptchaChallenge $e)') < (int) strpos($place, 'catch (UserError $error)')
    && strpos($place, 'catch (RecaptchaChallenge $e)') < (int) strpos($place, 'catch (RuntimeException $error)'));
check('place: in JSON la sfida risponde 422 con status 618', fn () =>
    (bool) preg_match('/catch \(RecaptchaChallenge \$e\) \{.*?if \(\$json\) \{\s*self::json\(\[\'success\' => false, \'status\' => RecaptchaChallenge::CHALLENGE_CODE, \'errors\' => \[\$e->getMessage\(\)\]\], 422\);\s*\}\s*self::reject\(\[\$e->getMessage\(\)\], \$post, \$json\);/s', $place));
check('rememberErrors non ricorda il tipo del token', fn () =>
    str_contains($metodo($file, 'rememberErrors'), "\$values['g-recaptcha-type']"));

summary();
