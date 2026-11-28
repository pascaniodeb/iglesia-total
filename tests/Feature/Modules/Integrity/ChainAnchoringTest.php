<?php

use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Anchoring\ChainAnchoring;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Domain\Anchoring\AnchorProof;
use App\Modules\Integrity\Domain\Anchoring\AnchorUnavailable;
use App\Modules\Integrity\Domain\Anchoring\ChainAnchor as Provider;
use App\Modules\Integrity\Infrastructure\Anchoring\OtsTimestamp;
use App\Modules\Integrity\Infrastructure\Anchoring\TimestampQuery;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\ChainAnchor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A provider that anchors nothing outside: it confirms at once or leaves the proof pending, can be
 * made to fail, and can be made to disagree when its proof is checked.
 */
final class FakeProvider implements Provider
{
    public bool $failing = false;

    public bool $proves = true;

    public bool $upgradable = false;

    public function __construct(private readonly string $name = 'rfc3161', private readonly bool $confirmsAtOnce = true) {}

    public function provider(): string
    {
        return $this->name;
    }

    public function submit(string $digest): AnchorProof
    {
        return $this->failing
            ? throw new AnchorUnavailable('offline')
            : new AnchorProof($this->confirmsAtOnce, ['query' => base64_encode('query'), 'reply' => base64_encode('reply'), 'digest' => bin2hex($digest)]);
    }

    public function upgrade(string $digest, AnchorProof $proof): ?AnchorProof
    {
        return $this->upgradable ? new AnchorProof(true, [...$proof->data, 'height' => 800000]) : null;
    }

    public function verifyOffline(string $digest, AnchorProof $proof): bool
    {
        return $this->proves;
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-28 14:00:00');
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    ActivityLogEntry::query()->count() > 0 || app(ActivityLog::class)->system('test.fact', 'A fact.');
    $this->provider = new FakeProvider;
    $this->anchoring = fn (Provider ...$providers) => new ChainAnchoring($providers ?: [$this->provider]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the head of each chain is pinned with the provider, once', function () {
    $result = ($this->anchoring)()->anchorHeads();

    $ledger = ChainAnchor::query()->where('chain', 'ledger')->sole();
    $activity = ChainAnchor::query()->where('chain', 'activity')->sole();

    expect($result['failed'])->toBe([])
        ->and($result['anchored'])->toHaveCount(2)
        ->and($ledger->position)->toBe(0)
        ->and(trim($ledger->hash))->toBe(trim(DB::table('ledger_blocks')->where('height', 0)->value('hash')))
        ->and($ledger->status)->toBe('confirmed')
        ->and($ledger->statement)->toBe("Iglesia Total sealed ledger (height)\nposition: 0\nhash: ".trim($ledger->hash)."\n")
        ->and(trim($ledger->digest))->toBe(hash('sha256', $ledger->statement))
        ->and($activity->position)->toBe((int) DB::table('activity_log')->max('seq'));

    expect(($this->anchoring)()->anchorHeads()['anchored'])->toBe([])
        ->and(ChainAnchor::query()->count())->toBe(2);
});

test('when the chains grow the new head is anchored too', function () {
    ($this->anchoring)()->anchorHeads();
    app(ActivityLog::class)->system('test.more', 'Another fact.');

    $result = ($this->anchoring)()->anchorHeads();

    expect($result['anchored'])->toHaveCount(1)
        ->and($result['anchored'][0]->chain)->toBe('activity')
        ->and(ChainAnchor::query()->where('chain', 'activity')->count())->toBe(2);
});

test('a provider that is down is reported and the others still anchor', function () {
    $down = new FakeProvider('opentimestamps');
    $down->failing = true;

    $result = ($this->anchoring)($this->provider, $down)->anchorHeads();

    expect($result['failed'])->toBe(['ledger:opentimestamps', 'activity:opentimestamps'])
        ->and(ChainAnchor::query()->where('provider', 'rfc3161')->count())->toBe(2)
        ->and(ChainAnchor::query()->where('provider', 'opentimestamps')->count())->toBe(0);
});

test('a pending proof becomes confirmed when the provider has it ready', function () {
    $pending = new FakeProvider('opentimestamps', confirmsAtOnce: false);
    ($this->anchoring)($pending)->anchorHeads();

    expect(ChainAnchor::query()->where('status', 'pending')->count())->toBe(2)
        ->and(($this->anchoring)($pending)->upgradePending())->toBe([]);

    $pending->upgradable = true;
    $confirmed = ($this->anchoring)($pending)->upgradePending();

    expect($confirmed)->toHaveCount(2)
        ->and(ChainAnchor::query()->where('status', 'confirmed')->count())->toBe(2)
        ->and(ChainAnchor::query()->first()->proof['height'])->toBe(800000);
});

test('the offline verification notices a block that was rewritten after it was anchored', function () {
    ($this->anchoring)()->anchorHeads();
    expect(($this->anchoring)()->verify()['rewritten'])->toBe([]);

    DB::statement('ALTER TABLE ledger_blocks DISABLE TRIGGER USER');
    DB::statement("UPDATE ledger_blocks SET hash = repeat('a', 64) WHERE height = 0");
    DB::statement('ALTER TABLE ledger_blocks ENABLE TRIGGER USER');

    expect(($this->anchoring)()->verify()['rewritten'])->toBe(['ledger:rfc3161:0']);
});

test('the offline verification notices a proof that no longer proves what it anchors', function () {
    ($this->anchoring)()->anchorHeads();
    $this->provider->proves = false;

    expect(($this->anchoring)()->verify()['invalid'])->toHaveCount(2);
});

test('an anchored chain that is intact reports its latest confirmation', function () {
    ($this->anchoring)()->anchorHeads();

    $result = ($this->anchoring)()->verify();

    expect($result['anchors'])->toBe(2)
        ->and($result['confirmed'])->toBe(2)
        ->and($result['pending'])->toBe(0)
        ->and($result['invalid'])->toBe([])
        ->and(array_keys($result['latest']))->toEqualCanonicalizing(['ledger', 'activity']);
});

test('an anchor can only go from pending to confirmed and is never erased', function () {
    $pending = new FakeProvider('opentimestamps', confirmsAtOnce: false);
    ($this->anchoring)($pending)->anchorHeads();
    $anchor = ChainAnchor::query()->where('chain', 'ledger')->sole();

    expect(fn () => DB::transaction(fn () => DB::table('chain_anchors')->where('id', $anchor->id)->update(['hash' => str_repeat('b', 64)])))->toThrow(QueryException::class, 'pending to confirmed')
        ->and(fn () => DB::transaction(fn () => DB::table('chain_anchors')->delete()))->toThrow(QueryException::class, 'append-only');

    $pending->upgradable = true;
    ($this->anchoring)($pending)->upgradePending();

    expect(fn () => DB::transaction(fn () => DB::table('chain_anchors')->where('id', $anchor->id)->update(['proof' => '{}'])))->toThrow(QueryException::class, 'pending to confirmed');
});

test('the statement and its digest cannot disagree', function () {
    expect(fn () => DB::transaction(fn () => DB::table('chain_anchors')->insert([
        'chain' => 'ledger', 'provider' => 'x', 'position' => 1, 'hash' => str_repeat('a', 64), 'statement' => 'one thing',
        'digest' => hash('sha256', 'another thing'), 'proof' => '{}',
    ])))->toThrow(QueryException::class, 'digest_matches');
});

test('the integrity center lists the anchors check, which passes while the chain holds and fails when it is rewritten', function () {
    $check = fn (): array => collect(app(IntegrityChecks::class)->run())->firstWhere('id', 'anchors.external');
    app()->bind(ChainAnchoring::class, fn () => ($this->anchoring)());
    ($this->anchoring)()->anchorHeads();

    expect($check()['passed'])->toBeTrue();

    DB::statement('ALTER TABLE ledger_blocks DISABLE TRIGGER USER');
    DB::statement("UPDATE ledger_blocks SET hash = repeat('a', 64) WHERE height = 0");
    DB::statement('ALTER TABLE ledger_blocks ENABLE TRIGGER USER');

    expect($check()['passed'])->toBeFalse()
        ->and($check()['findings'][0])->toContain('ledger:rfc3161:0');
});

test('the command refuses to publish while anchoring is switched off', function () {
    config(['integrity.anchor.enabled' => false]);

    $this->artisan('integrity:anchor')->assertFailed();

    expect(ChainAnchor::query()->count())->toBe(0);
});

test('the command anchors, records it and exports the proofs as standard files', function () {
    config(['integrity.anchor.enabled' => true]);
    app()->bind(ChainAnchoring::class, fn () => ($this->anchoring)());

    $this->artisan('integrity:anchor')->assertSuccessful();

    expect(ChainAnchor::query()->count())->toBe(2)
        ->and(ActivityLogEntry::query()->where('action', 'integrity.anchored')->count())->toBe(2);

    $folder = sys_get_temp_dir().'/anchors-'.bin2hex(random_bytes(4));
    $this->artisan('integrity:anchor', ['--export' => $folder])->assertSuccessful();

    $files = array_map('basename', glob("{$folder}/*") ?: []);
    expect($files)->toContain('anchor-ledger-0-rfc3161.txt', 'anchor-ledger-0-rfc3161.tsq', 'anchor-ledger-0-rfc3161.tsr');
    array_map('unlink', glob("{$folder}/*") ?: []);
    rmdir($folder);
});

test('the RFC 3161 request is DER for a SHA-256 digest with its nonce', function () {
    $digest = hash('sha256', 'x', true);
    $query = TimestampQuery::build($digest, "\x01\x02\x03\x04\x05\x06\x07\x08");

    expect($query)->toStartWith("\x30")
        ->and($query)->toContain("\x04\x20".$digest)
        ->and($query)->toEndWith("\x01\x01\xff")
        ->and(fn () => TimestampQuery::build('short', "\x01\x02\x03\x04\x05\x06\x07\x08"))->toThrow(InvalidArgumentException::class);
});

test('an OpenTimestamps receipt with a pending attestation is read and written back unchanged', function () {
    $digest = hash('sha256', 'x', true);
    $uri = 'https://alice.btc.calendar.opentimestamps.org';
    // The official format: the payload is itself a length-prefixed string.
    $payload = chr(strlen($uri)).$uri;
    $receipt = "\x00".OtsTimestamp::PENDING.chr(strlen($payload)).$payload;

    $timestamp = OtsTimestamp::parse($receipt, $digest);

    expect($timestamp->pending())->toBe([['commitment' => $digest, 'uri' => $uri]])
        ->and($timestamp->bitcoin())->toBe([])
        ->and($timestamp->serialize())->toBe($receipt)
        ->and(fn () => OtsTimestamp::parse($receipt."\x00", $digest))->toThrow(InvalidArgumentException::class);
});

test('a receipt a real calendar returned names its calendar without a stray length byte', function () {
    // Returned by a.pool.opentimestamps.org for a dummy digest (sha256 of "wire-format-check-not-real-data").
    $receipt = (string) hex2bin('f008f6b17b3d2a1766f008f120f547da1a000b0fa252feaf4af75b47d71c58cbe1981c1539bbd6a09af055084e08f020d834e6730f964a55b7ab3880193ee443d888a33cacd4160f8607beecf0ccbb8808f010163b75e5ff518ba117c327681f86a79508f1208dbfae13048dec558591b2aab33c975d087028653d82d84b29e33fc67b5ca6ef08f120fffb5fa8b3261a92e5cdee4260ac5a5601457acaecad577b5e478307d6493ea908f1046b0ab5e0f0087d1fa274dc6c81ce0083dfe30d2ef90c8e2e2d68747470733a2f2f616c6963652e6274632e63616c656e6461722e6f70656e74696d657374616d70732e6f7267');

    $timestamp = OtsTimestamp::parse($receipt, hash('sha256', 'wire-format-check-not-real-data', true));

    expect($timestamp->pending())->toHaveCount(1)
        ->and($timestamp->pending()[0]['uri'])->toBe('https://alice.btc.calendar.opentimestamps.org')
        ->and($timestamp->serialize())->toBe($receipt);
});
