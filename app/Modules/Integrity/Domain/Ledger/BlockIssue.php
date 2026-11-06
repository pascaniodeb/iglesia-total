<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * Something wrong with a sealed block. The two types demand different answers:
 *
 * - `chain`: a stored block no longer matches its own hashes or its parent. Someone rewrote
 *   the table: a security incident.
 * - `drift`: the block is intact, but the live books no longer produce the payload that was
 *   sealed. Data moved after the close: the period has to be investigated.
 *
 * The domain does not translate: `$message` is an English sentence with `:placeholders`, which
 * is also its translation key, and `$params` fills them (`__($issue->message, $issue->params)`).
 */
final readonly class BlockIssue
{
    public const string CHAIN = 'chain';

    public const string DRIFT = 'drift';

    /**
     * @param  self::CHAIN|self::DRIFT  $type
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public string $type,
        public int $height,
        public string $kind,
        public ?string $periodKey,
        public string $message,
        public array $params = [],
    ) {}

    /**
     * The message with its placeholders filled, untranslated (logs and tests).
     */
    public function detail(): string
    {
        $replacements = [];

        foreach ($this->params as $key => $value) {
            $replacements[":{$key}"] = (string) $value;
        }

        return strtr($this->message, $replacements);
    }

    /**
     * @return array{type: string, height: int, kind: string, period_key: string|null, message: string, params: array<string, string|int>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'height' => $this->height,
            'kind' => $this->kind,
            'period_key' => $this->periodKey,
            'message' => $this->message,
            'params' => $this->params,
        ];
    }
}
