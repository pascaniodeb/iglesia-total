<?php

namespace App\Modules\Integrity\Infrastructure\Anchoring;

use App\Modules\Integrity\Domain\Anchoring\AnchorProof;
use App\Modules\Integrity\Domain\Anchoring\AnchorUnavailable;
use App\Modules\Integrity\Domain\Anchoring\BitcoinHeaders;
use App\Modules\Integrity\Domain\Anchoring\ChainAnchor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * OpenTimestamps: calendars aggregate digests and anchor them in Bitcoin (free, no wallet). The
 * initial receipt stays "pending"; hours later the calendar hands over the path to the Merkle root
 * of a block. That root is checked against the block header.
 *
 * Only calendars on the whitelist (https) are queried: a URL carried by a receipt is never followed
 * blindly.
 */
final readonly class OpenTimestampsAnchor implements ChainAnchor
{
    private const string ACCEPT = 'application/vnd.opentimestamps.v1';

    private const int MAX_RESPONSE = 10000;

    /**
     * @param  list<string>  $aggregators  where the digests are sent
     * @param  list<string>  $whitelist  hosts allowed when upgrading (wildcard *. accepted)
     */
    public function __construct(
        private array $aggregators,
        private array $whitelist,
        private BitcoinHeaders $headers,
        private int $timeout,
    ) {}

    public function provider(): string
    {
        return 'opentimestamps';
    }

    public function submit(string $digest): AnchorProof
    {
        $receipts = [];

        foreach ($this->aggregators as $aggregator) {
            try {
                $response = Http::timeout($this->timeout)->accept(self::ACCEPT)
                    ->withBody($digest, 'application/x-www-form-urlencoded')
                    ->post(rtrim($aggregator, '/').'/digest');

                if ($response->successful() && strlen($response->body()) <= self::MAX_RESPONSE) {
                    OtsTimestamp::parse($response->body(), $digest); // validated before it is stored
                    $receipts[] = ['calendar' => $aggregator, 'timestamp' => base64_encode($response->body())];
                }
            } catch (ConnectionException|InvalidArgumentException $e) {
                Log::warning('OpenTimestamps calendar unavailable.', ['calendar' => $aggregator, 'error' => $e->getMessage()]);
            }
        }

        if ($receipts === []) {
            throw new AnchorUnavailable('No OpenTimestamps calendar accepted the anchor.');
        }

        return new AnchorProof(false, ['receipts' => $receipts]);
    }

    public function upgrade(string $digest, AnchorProof $proof): ?AnchorProof
    {
        foreach ($proof->data['receipts'] ?? [] as $receipt) {
            $confirmed = $this->upgradeReceipt(OtsTimestamp::parse((string) base64_decode($receipt['timestamp'], true), $digest));

            if ($confirmed !== null) {
                return new AnchorProof(true, [...$proof->data, 'calendar' => $receipt['calendar'], ...$confirmed]);
            }
        }

        return null;
    }

    public function verifyOffline(string $digest, AnchorProof $proof): bool
    {
        $timestamp = base64_decode((string) ($proof->data['timestamp'] ?? ''), true);
        $merkleRoot = (string) ($proof->data['merkle_root'] ?? '');

        if ($timestamp === false || $timestamp === '' || $merkleRoot === '') {
            return false;
        }

        try {
            $attestations = OtsTimestamp::parse($timestamp, $digest)->bitcoin();
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach ($attestations as $attestation) {
            if ($attestation['height'] === ($proof->data['height'] ?? null) && hash_equals($merkleRoot, bin2hex(strrev($attestation['commitment'])))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Asks the calendar for the path to Bitcoin and checks it against the block header.
     *
     * @return array{timestamp: string, height: int, merkle_root: string}|null
     */
    private function upgradeReceipt(OtsTimestamp $receipt): ?array
    {
        foreach ($receipt->pending() as ['commitment' => $commitment, 'uri' => $uri]) {
            if (! $this->allowed($uri)) {
                Log::warning('OpenTimestamps calendar outside the whitelist.', ['uri' => $uri]);

                continue;
            }

            try {
                $response = Http::timeout($this->timeout)->accept(self::ACCEPT)->get(rtrim($uri, '/').'/timestamp/'.bin2hex($commitment));
            } catch (ConnectionException) {
                continue;
            }

            if (! $response->successful() || strlen($response->body()) > self::MAX_RESPONSE) {
                continue; // 404: not in a block yet
            }

            $upgraded = $receipt->graft($commitment, OtsTimestamp::parse($response->body(), $commitment));

            foreach ($upgraded->bitcoin() as ['commitment' => $root, 'height' => $height]) {
                // The header stores the root in internal order; explorers show it reversed.
                $merkleRoot = bin2hex(strrev($root));

                if ($this->headers->merkleRootAt($height) === $merkleRoot) {
                    return ['timestamp' => base64_encode($upgraded->serialize()), 'height' => $height, 'merkle_root' => $merkleRoot];
                }

                Log::warning('The Merkle root of the anchor does not match the block.', ['height' => $height]);
            }
        }

        return null;
    }

    private function allowed(string $uri): bool
    {
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['query'])) {
            return false;
        }

        foreach ($this->whitelist as $pattern) {
            if (fnmatch($pattern, $parts['host'])) {
                return true;
            }
        }

        return false;
    }
}
