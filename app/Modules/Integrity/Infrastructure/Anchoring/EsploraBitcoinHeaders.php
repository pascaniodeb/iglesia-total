<?php

namespace App\Modules\Integrity\Infrastructure\Anchoring;

use App\Modules\Integrity\Domain\Anchoring\BitcoinHeaders;
use Illuminate\Support\Facades\Http;

/**
 * Headers from an Esplora API (blockstream.info or mempool.space, interchangeable).
 */
final readonly class EsploraBitcoinHeaders implements BitcoinHeaders
{
    public function __construct(private string $baseUrl, private int $timeout) {}

    public function merkleRootAt(int $height): ?string
    {
        $hash = Http::timeout($this->timeout)->get("{$this->baseUrl}/block-height/{$height}");

        if (! $hash->successful() || ! preg_match('/^[0-9a-f]{64}$/', trim($hash->body()))) {
            return null;
        }

        $block = Http::timeout($this->timeout)->get("{$this->baseUrl}/block/".trim($hash->body()));
        $root = $block->successful() ? $block->json('merkle_root') : null;

        return is_string($root) && preg_match('/^[0-9a-f]{64}$/', $root) ? $root : null;
    }
}
