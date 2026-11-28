<?php

use App\Modules\Integrity\Domain\Anchoring\AnchorProof;
use App\Modules\Integrity\Domain\Anchoring\AnchorUnavailable;
use App\Modules\Integrity\Domain\Anchoring\BitcoinHeaders;
use App\Modules\Integrity\Infrastructure\Anchoring\OpenTimestampsAnchor;
use App\Modules\Integrity\Infrastructure\Anchoring\OtsTimestamp;
use App\Modules\Integrity\Infrastructure\Anchoring\Rfc3161Anchor;
use App\Modules\Integrity\Infrastructure\Models\ChainAnchor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\LocalTimestampAuthority;

// The two providers against real signatures and the real wire format, with no network: a local
// timestamp authority that signs for real, and calendars and block headers faked.

const OTS_CALENDAR = 'https://alice.btc.calendar.opentimestamps.org';

function providerDigest(): string
{
    return hash('sha256', ChainAnchor::statementFor('ledger', 7, str_repeat('a', 64)), true);
}

function localTsa(): Rfc3161Anchor
{
    return new Rfc3161Anchor([['url' => 'https://tsa.test/tsr', 'ca_file' => LocalTimestampAuthority::caFile(), 'cert_file' => LocalTimestampAuthority::certFile()]], 'openssl', 20);
}

function fakeTsa(): void
{
    Http::fake(['tsa.test/*' => fn (Request $request) => Http::response(LocalTimestampAuthority::reply($request->body()), 200, ['Content-Type' => 'application/timestamp-reply'])]);
}

/**
 * A calendar's receipt in the official wire format: append(nonce), sha256, then a pending
 * attestation whose payload is itself a length-prefixed URL.
 *
 * @return array{receipt: string, commitment: string}
 */
function otsReceipt(string $digest, string $calendar = OTS_CALENDAR): array
{
    $nonce = str_repeat("\x11", 16);
    $payload = chr(strlen($calendar)).$calendar;

    return [
        'receipt' => "\xf0\x10{$nonce}\x08\x00".OtsTimestamp::PENDING.chr(strlen($payload)).$payload,
        'commitment' => hash('sha256', $digest.$nonce, true),
    ];
}

/**
 * A calendar's upgrade: prepend(x), sha256, then the attestation of Bitcoin block 900000.
 *
 * @return array{upgrade: string, merkle_root: string}
 */
function otsUpgrade(string $commitment): array
{
    $prefix = str_repeat("\x22", 32);

    return [
        'upgrade' => "\xf1\x20{$prefix}\x08\x00".OtsTimestamp::BITCOIN."\x03\xa0\xf7\x36", // varuint(900000)
        'merkle_root' => bin2hex(strrev(hash('sha256', $prefix.$commitment, true))),
    ];
}

function otsAnchor(?string $merkleRoot): OpenTimestampsAnchor
{
    $headers = new class($merkleRoot) implements BitcoinHeaders
    {
        public function __construct(private ?string $root) {}

        public function merkleRootAt(int $height): ?string
        {
            return $height === 900000 ? $this->root : null;
        }
    };

    return new OpenTimestampsAnchor(['https://a.pool.opentimestamps.org'], ['*.calendar.opentimestamps.org'], $headers, 5);
}

test('the authority signs in seconds and the signature verifies offline against its root certificate', function () {
    fakeTsa();

    $proof = localTsa()->submit(providerDigest());

    expect($proof->confirmed)->toBeTrue()
        ->and($proof->provenAt?->diffInMinutes(now(), true))->toBeLessThan(2)
        ->and(localTsa()->verifyOffline(providerDigest(), $proof))->toBeTrue()
        ->and(localTsa()->verifyOffline(hash('sha256', 'something else', true), $proof))->toBeFalse();
});

test('a tampered stamp or one from an authority that is not trusted is refused', function () {
    fakeTsa();
    $proof = localTsa()->submit(providerDigest());
    $reply = base64_decode($proof->data['reply']);
    $tampered = new AnchorProof(true, [...$proof->data, 'reply' => base64_encode(substr($reply, 0, -10).str_repeat("\x00", 10))]);

    $untrusted = new Rfc3161Anchor([['url' => 'https://tsa.test/tsr', 'ca_file' => 'resources/certificates/tsa/freetsa-cacert.pem', 'cert_file' => null]], 'openssl', 20);

    expect(localTsa()->verifyOffline(providerDigest(), $tampered))->toBeFalse()
        ->and(fn () => $untrusted->submit(providerDigest()))->toThrow(AnchorUnavailable::class);
});

test('an OpenTimestamps proof is read and written back byte for byte', function () {
    ['receipt' => $receipt, 'commitment' => $commitment] = otsReceipt(providerDigest());
    $proof = OtsTimestamp::parse($receipt, providerDigest());

    expect($proof->serialize())->toBe($receipt)
        ->and($proof->pending())->toBe([['commitment' => $commitment, 'uri' => OTS_CALENDAR]])
        ->and($proof->detachedFile())->toStartWith(OtsTimestamp::FILE_MAGIC."\x01\x08".providerDigest())
        ->and(fn () => OtsTimestamp::parse($receipt."\x00", providerDigest()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OtsTimestamp::parse("\x55", providerDigest()))->toThrow(InvalidArgumentException::class, 'Unknown');
});

test('the receipt stays pending and is confirmed when Bitcoin includes it in a verified block', function () {
    ['receipt' => $receipt, 'commitment' => $commitment] = otsReceipt(providerDigest());
    ['upgrade' => $upgrade, 'merkle_root' => $root] = otsUpgrade($commitment);
    Http::fake([
        'a.pool.opentimestamps.org/digest' => Http::response($receipt),
        'alice.btc.calendar.opentimestamps.org/timestamp/'.bin2hex($commitment) => Http::response($upgrade),
    ]);

    $pending = otsAnchor($root)->submit(providerDigest());
    $confirmed = otsAnchor($root)->upgrade(providerDigest(), $pending);

    expect($pending->confirmed)->toBeFalse()
        ->and($confirmed?->confirmed)->toBeTrue()
        ->and($confirmed?->data)->toMatchArray(['height' => 900000, 'merkle_root' => $root])
        ->and(otsAnchor($root)->verifyOffline(providerDigest(), $confirmed))->toBeTrue()
        ->and(otsAnchor($root)->verifyOffline(providerDigest(), new AnchorProof(true, [...$confirmed->data, 'merkle_root' => str_repeat('0', 64)])))->toBeFalse();
});

test('if the root does not match the real block the anchor stays pending', function () {
    ['receipt' => $receipt, 'commitment' => $commitment] = otsReceipt(providerDigest());
    Http::fake([
        'a.pool.opentimestamps.org/digest' => Http::response($receipt),
        'alice.btc.calendar.opentimestamps.org/*' => Http::response(otsUpgrade($commitment)['upgrade']),
    ]);

    $pending = otsAnchor(str_repeat('f', 64))->submit(providerDigest());

    expect(otsAnchor(str_repeat('f', 64))->upgrade(providerDigest(), $pending))->toBeNull();
});

test('it never queries a calendar outside the whitelist', function () {
    ['receipt' => $receipt] = otsReceipt(providerDigest(), 'https://evil.example.com');
    Http::fake(['a.pool.opentimestamps.org/digest' => Http::response($receipt), '*' => Http::response('', 500)]);

    $pending = otsAnchor(null)->submit(providerDigest());

    expect(otsAnchor(null)->upgrade(providerDigest(), $pending))->toBeNull();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
});
