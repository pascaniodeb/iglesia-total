<?php

namespace App\Modules\Integrity\Application\Audit;

use App\Modules\Integrity\Domain\Audit\AuditCheck;
use InvalidArgumentException;

/**
 * The audit checks the modules register (from their service providers), one per key.
 */
final class AuditChecks
{
    /** @var array<string, AuditCheck> */
    private array $checks = [];

    public function register(AuditCheck $check): void
    {
        if (isset($this->checks[$check->id()])) {
            throw new InvalidArgumentException("The audit check {$check->id()} is already registered.");
        }

        $this->checks[$check->id()] = $check;
    }

    /**
     * @return list<AuditCheck>
     */
    public function all(): array
    {
        return array_values($this->checks);
    }
}
