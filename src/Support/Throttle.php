<?php

namespace Wonder\Plugin\Ecommerce\Support;

/**
 * Un limite di tentativi in una finestra di tempo, su file.
 *
 * Ogni coppia gruppo e chiave è un file con gli istanti dei tentativi; quelli
 * fuori dalla finestra si buttano a ogni scrittura e il file vuoto se ne va.
 * Il core non ha ancora un limitatore comune: quando ci sarà, questa classe
 * passa a lui.
 */
final class Throttle
{
    private static ?string $directory = null;

    /** La cartella dei contatori; null torna a quella del sito. Serve ai test. */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory === null ? null : rtrim($directory, '/');
    }

    /** Si può ancora provare: i tentativi nella finestra sono meno del limite. Una chiave vuota non ha limite. */
    public static function allowed(string $bucket, string $key, int $limit, int $window): bool
    {
        if ($key === '') {
            return true;
        }

        $file = self::file($bucket, $key);
        if (!is_file($file)) {
            return true;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return true;
        }

        flock($handle, LOCK_SH);
        $times = self::recent((string) stream_get_contents($handle), $window);
        flock($handle, LOCK_UN);
        fclose($handle);

        return count($times) < $limit;
    }

    /** Conta un tentativo adesso. */
    public static function hit(string $bucket, string $key, int $window): void
    {
        if ($key === '') {
            return;
        }

        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $file = self::file($bucket, $key);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        $times = self::recent((string) stream_get_contents($handle), $window);
        $times[] = time();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($times));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** L'indirizzo di chi chiama, così come lo vede il server: un'intestazione si falsifica. */
    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** @return list<int> */
    private static function recent(string $content, int $window): array
    {
        $since = time() - $window;
        $times = json_decode($content, true);

        return is_array($times)
            ? array_values(array_filter(array_map('intval', $times), static fn (int $time): bool => $time > $since))
            : [];
    }

    private static function file(string $bucket, string $key): string
    {
        return self::directory().'/'.hash('sha256', $bucket.'|'.$key).'.json';
    }

    private static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $root = defined('ROOT') ? (string) constant('ROOT') : (string) getcwd();

        return rtrim($root, '/').'/storage/ecommerce/throttle';
    }
}
