<?php

namespace App\Modules\Integrity\Infrastructure\Anchoring;

use App\Modules\Integrity\Domain\Anchoring\AnchorProof;
use App\Modules\Integrity\Domain\Anchoring\AnchorUnavailable;
use App\Modules\Integrity\Domain\Anchoring\ChainAnchor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * RFC 3161 timestamp: an authority (TSA) signs the digest with its time within seconds. The
 * signature is verified with `openssl ts` against the authority's root certificate, kept in the
 * repository with its published fingerprint: the proof can be checked offline with standard tools.
 */
final readonly class Rfc3161Anchor implements ChainAnchor
{
    /**
     * @param  list<array{url: string, ca_file: string, cert_file: string|null}>  $authorities
     */
    public function __construct(private array $authorities, private string $openssl, private int $timeout) {}

    public function provider(): string
    {
        return 'rfc3161';
    }

    public function submit(string $digest): AnchorProof
    {
        foreach ($this->authorities as $authority) {
            $query = TimestampQuery::build($digest, random_bytes(8));

            try {
                $response = Http::timeout($this->timeout)
                    ->withBody($query, 'application/timestamp-query')
                    ->post($authority['url']);
            } catch (ConnectionException $e) {
                Log::warning('Timestamp authority unavailable.', ['tsa' => $authority['url'], 'error' => $e->getMessage()]);

                continue;
            }

            $proof = new AnchorProof(true, [
                'tsa' => $authority['url'],
                'query' => base64_encode($query),
                'reply' => base64_encode($response->body()),
            ]);

            if ($response->successful() && $this->verifyOffline($digest, $proof)) {
                return new AnchorProof(true, $proof->data, $this->stampedAt($response->body()));
            }

            Log::warning('Timestamp rejected: the signature or the digest do not verify.', ['tsa' => $authority['url'], 'status' => $response->status()]);
        }

        throw new AnchorUnavailable('No timestamp authority returned a valid stamp.');
    }

    public function upgrade(string $digest, AnchorProof $proof): ?AnchorProof
    {
        return null; // the stamp is born confirmed
    }

    public function verifyOffline(string $digest, AnchorProof $proof): bool
    {
        $authority = collect($this->authorities)->firstWhere('url', $proof->data['tsa'] ?? null);
        $query = base64_decode((string) ($proof->data['query'] ?? ''), true);
        $reply = base64_decode((string) ($proof->data['reply'] ?? ''), true);

        // The request must be for this digest: openssl compares the nonce and the imprint with the reply.
        if ($authority === null || $query === false || $reply === false || ! str_contains($query, "\x04\x20".$digest)) {
            return false;
        }

        return $this->withFiles(['query.tsq' => $query, 'reply.tsr' => $reply], function (array $paths) use ($authority): bool {
            $command = [$this->openssl, 'ts', '-verify', '-queryfile', $paths['query.tsq'], '-in', $paths['reply.tsr'], '-CAfile', self::path($authority['ca_file'])];

            if ($authority['cert_file'] !== null) {
                array_push($command, '-untrusted', self::path($authority['cert_file']));
            }

            $result = Process::timeout($this->timeout)->run($command);

            return $result->successful() && str_contains($result->output(), 'Verification: OK');
        });
    }

    /**
     * Certificate paths: relative to the project or absolute.
     */
    private static function path(string $file): string
    {
        return str_starts_with($file, '/') ? $file : base_path($file);
    }

    /**
     * The time the authority certifies (genTime), read from the stamp.
     */
    private function stampedAt(string $reply): ?CarbonImmutable
    {
        $text = $this->withFiles(['reply.tsr' => $reply], fn (array $paths): string => Process::timeout($this->timeout)
            ->run([$this->openssl, 'ts', '-reply', '-in', $paths['reply.tsr'], '-text'])
            ->output());

        if (! preg_match('/^Time stamp: (.+)$/m', $text, $match)) {
            return null;
        }

        return CarbonImmutable::parse(Str::squish(preg_replace('/\.\d+/', '', $match[1]) ?? $match[1]))->utc();
    }

    /**
     * @template T
     *
     * @param  array<string, string>  $files
     * @param  callable(array<string, string>): T  $callback
     * @return T
     */
    private function withFiles(array $files, callable $callback): mixed
    {
        $dir = sys_get_temp_dir().'/rfc3161-'.Str::random(16);
        mkdir($dir, 0700);
        $paths = [];

        try {
            foreach ($files as $name => $contents) {
                file_put_contents($paths[$name] = "{$dir}/{$name}", $contents);
            }

            return $callback($paths);
        } finally {
            array_map('unlink', array_values($paths));
            rmdir($dir);
        }
    }
}
