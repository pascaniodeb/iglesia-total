<?php

namespace App\Modules\Integrity\Application\Ledger;

use App\Modules\Integrity\Domain\Ledger\SealableSource;
use InvalidArgumentException;

/**
 * The sources registered by the modules (from their service providers), one per block kind.
 */
final class SealableSources
{
    public const array KINDS = ['weekly', 'monthly'];

    /** @var array<string, SealableSource> */
    private array $sources = [];

    public function register(SealableSource $source): void
    {
        $kind = $source->kind();

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown block kind: {$kind}.");
        }

        $this->sources[$kind] = $source;
    }

    public function for(string $kind): ?SealableSource
    {
        return $this->sources[$kind] ?? null;
    }

    /**
     * @return array<string, SealableSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
