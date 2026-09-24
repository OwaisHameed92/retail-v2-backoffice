<?php

namespace App\Domain\Licensing\Data;

use Countable;

/**
 * Several licences issued in one go (IssueMissingLicences), each with its plain key.
 */
final readonly class IssuedLicences implements Countable
{
    /**
     * @param  list<IssuedLicence>  $items
     */
    public function __construct(public array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
