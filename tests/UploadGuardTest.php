<?php
/** php tests/UploadGuardTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);

// Oggi il modulo non riceve file (C-UPL-7): chi ne aggiunge uno passa da UploadValidator del core.
check('nessun ingresso file senza UploadValidator::validate()', function () use ($root) {
    $found = [];
    foreach (['src', 'http', 'view'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $input = str_contains($source, '$_FILES') || preg_match('/type\s*=\s*["\']?file\b/i', $source) === 1;
            if ($input && !str_contains($source, 'UploadValidator::validate(')) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    if ($found !== []) {
        throw new RuntimeException(implode(', ', $found));
    }

    return true;
});

check('UploadValidator::validate() esiste nel core installato', fn () =>
    method_exists(\Wonder\App\Support\UploadValidator::class, 'validate'));

summary();
