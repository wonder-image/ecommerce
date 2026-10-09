<?php
declare(strict_types=1);

/**
 * Un file di `tests/supporto/` del pacchetto gestionale installato.
 *
 * La cartella si ricava da dove sta la classe `Checkout` già caricata
 * dall'autoload (non da un percorso scritto a mano): nel sito di prova è
 * il gestionale collegato, in un worktree è quello del worktree.
 * Serve l'autoload di composer già caricato. Se il file non c'è, `null`.
 */
if (!function_exists('gestionaleSupporto')) {
function gestionaleSupporto(string $file): ?string
{
    $class = 'Wonder\\Plugin\\Gestionale\\Support\\Orders\\Checkout';

    if (!class_exists($class)) {
        return null;
    }

    // …/gestionale/src/Support/Orders/Checkout.php → …/gestionale
    $root = dirname((string) (new ReflectionClass($class))->getFileName(), 4);
    $path = $root.'/tests/supporto/'.$file;

    return is_file($path) ? $path : null;
}
}
