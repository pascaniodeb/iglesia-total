<?php

use App\Modules\Integrity\Domain\Ledger\BlockHash;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Domain\Ledger\ChainVerifier;
use App\Modules\Integrity\Domain\Ledger\MerkleStep;
use App\Modules\Integrity\Domain\Ledger\MerkleTree;
use App\Modules\Integrity\Domain\Ledger\SealedBlock;

/*
 * Golden fixtures. `ledger-golden.json` was produced by running the old system's own
 * src/lib/ledger/hashing.ts (canonicalize, merkleRoot, merkleProof, blockHash) with Bun;
 * `legacy-ledger-blocks.json` holds real blocks of the old sealed ledger (genesis, two weeks
 * and a month, no personal data). The PHP port must reproduce them byte for byte: that is what
 * lets the new system verify the 110 blocks the old one sealed.
 */

function ledgerFixture(string $name): mixed
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/Integrity/{$name}.json"));
}

/**
 * @return list<SealedBlock>
 */
function legacyBlocks(): array
{
    return array_map(fn (object $row) => new SealedBlock(
        height: $row->height,
        kind: $row->kind,
        periodKey: $row->period_key,
        prevHash: $row->prev_hash,
        merkleRoot: $row->merkle_root,
        payloadHash: $row->payload_hash,
        hash: $row->hash,
        payload: $row->payload,
    ), ledgerFixture('legacy-ledger-blocks'));
}

test('serializes every golden case exactly as the old system', function () {
    foreach (ledgerFixture('ledger-golden')->canonical as $case) {
        expect(Canonical::serialize($case->value))->toBe($case->canonical, $case->name)
            ->and(Canonical::hash($case->value))->toBe($case->hash, $case->name);
    }
});

test('associative arrays and objects serialize the same way', function () {
    expect(Canonical::serialize(['b' => '2', 'a' => ['y' => true, 'x' => null]]))
        ->toBe(Canonical::serialize((object) ['a' => (object) ['x' => null, 'y' => true], 'b' => '2']))
        ->toBe('{"a":{"x":null,"y":true},"b":"2"}');
});

test('refuses to seal floats, invalid text and unknown types', function (mixed $value) {
    expect(fn () => Canonical::serialize($value))->toThrow(InvalidArgumentException::class);
})->with([
    'float amount' => [1144500.0],
    'nested float' => [['ves' => 0.1]],
    'invalid utf-8' => ["\xB1\x31"],
    'object' => [new DateTimeImmutable],
]);

test('computes the same Merkle roots and proofs as the old system for 0 to 9 leaves', function () {
    foreach (ledgerFixture('ledger-golden')->merkle as $tree) {
        expect(MerkleTree::root($tree->leaves))->toBe($tree->root);

        foreach ($tree->proofs as $index => $expected) {
            $proof = MerkleTree::proof($tree->leaves, $index);

            expect(array_map(fn (MerkleStep $step) => $step->toArray(), $proof))
                ->toBe(array_map(fn (object $step) => ['side' => $step->side, 'hash' => $step->hash], $expected))
                ->and(MerkleTree::verify($tree->leaves[$index], $proof, $tree->root))->toBeTrue();
        }
    }
});

test('a proof does not verify another leaf or a tampered root', function () {
    $leaves = array_map(fn (int $i) => hash('sha256', "leaf-{$i}"), range(0, 4));
    $root = MerkleTree::root($leaves);
    $proof = MerkleTree::proof($leaves, 2);

    expect(MerkleTree::verify($leaves[3], $proof, $root))->toBeFalse()
        ->and(MerkleTree::verify($leaves[2], $proof, str_repeat('f', 64)))->toBeFalse();
});

test('computes the same block hashes as the old system', function () {
    foreach (ledgerFixture('ledger-golden')->blocks as $block) {
        expect(BlockHash::of($block->height, $block->prevHash, $block->merkleRoot, $block->payloadHash))->toBe($block->hash);
    }
});

test('recomputes the payload and block hashes of real blocks sealed by the old system', function () {
    foreach (legacyBlocks() as $block) {
        expect(Canonical::hash($block->payload))->toBe($block->payloadHash, "payload of block {$block->height}")
            ->and(BlockHash::of($block->height, $block->prevHash, $block->merkleRoot, $block->payloadHash))->toBe($block->hash, "block {$block->height}");
    }
});

test('verifies the start of the old chain as intact', function () {
    $start = array_slice(legacyBlocks(), 0, 3);

    expect(ChainVerifier::verify($start))->toBe([]);
});

test('detects a tampered payload, a broken link and a gap', function () {
    [$genesis, $week1, $week2] = array_slice(legacyBlocks(), 0, 3);

    $tamperedPayload = clone $week1->payload;
    $tamperedPayload->offeringsCount = 8;
    $tampered = new SealedBlock($week1->height, $week1->kind, $week1->periodKey, $week1->prevHash, $week1->merkleRoot, $week1->payloadHash, $week1->hash, $tamperedPayload);

    $issues = ChainVerifier::verify([$genesis, $tampered, $week2]);
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->type)->toBe(BlockIssue::CHAIN)
        ->and($issues[0]->height)->toBe(1);

    $gap = ChainVerifier::verify([$genesis, $week2]);
    expect(array_map(fn (BlockIssue $issue) => $issue->height, $gap))->toBe([2, 2]);
});
