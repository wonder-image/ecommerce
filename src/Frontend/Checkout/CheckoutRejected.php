<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;

/** Il checkout non si chiude: campi mancanti, o il totale è cambiato (allora c'è il nuovo riepilogo). */
final class CheckoutRejected extends RuntimeException
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $summary
     */
    public function __construct(private array $errors, private ?array $summary = null)
    {
        parent::__construct(implode("\n", $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed>|null */
    public function summary(): ?array
    {
        return $this->summary;
    }

    public function totalChanged(): bool
    {
        return $this->summary !== null;
    }
}
