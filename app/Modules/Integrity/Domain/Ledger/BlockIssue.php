<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * Something wrong with a sealed block. The two types demand different answers:
 *
 * - `chain`: a stored block no longer matches its own hashes or its parent. Someone rewrote
 *   the table: a security incident.
 * - `drift`: the block is intact, but the live books no longer produce the payload that was
 *   sealed. Data moved after the close: the period has to be investigated.
 */
final readonly class BlockIssue
{
    public const string CHAIN = 'chain';

    public const string DRIFT = 'drift';

    /**
     * @param  self::CHAIN|self::DRIFT  $type
     */
    public function __construct(
        public string $type,
        public int $height,
        public string $kind,
        public ?string $periodKey,
        public string $detail,
    ) {}

    /**
     * @return array{type: string, height: int, kind: string, period_key: string|null, detail: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'height' => $this->height,
            'kind' => $this->kind,
            'period_key' => $this->periodKey,
            'detail' => $this->detail,
        ];
    }
}
