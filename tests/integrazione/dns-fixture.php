<?php
declare(strict_types=1);

namespace Wonder\Data\Validators;

// Reserved fixture domain: tests never depend on public DNS or send email.
function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    return strtolower($hostname) === 'example.com' ? true : \checkdnsrr($hostname, $type);
}
