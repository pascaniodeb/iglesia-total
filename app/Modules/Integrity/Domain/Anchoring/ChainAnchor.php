<?php

namespace App\Modules\Integrity\Domain\Anchoring;

/**
 * External anchor of a chain head: publishes a digest (never data) in a system nobody in the
 * organization controls, so that rewriting history is provable even for someone with full access
 * to the database. Two, complementary and wallet-free:
 *
 * - RFC 3161: a timestamp authority signs the digest within seconds (legal value).
 * - OpenTimestamps: Bitcoin confirms it hours later, trusting no authority.
 */
interface ChainAnchor
{
    public function provider(): string;

    /**
     * Anchors the digest (32 bytes): the proof can come back confirmed or pending.
     *
     * @throws AnchorUnavailable if no service accepted it
     */
    public function submit(string $digest): AnchorProof;

    /**
     * Completes a pending proof; null while it is not ready.
     */
    public function upgrade(string $digest, AnchorProof $proof): ?AnchorProof;

    /**
     * Checks, with no network, that a confirmed proof belongs to the digest.
     */
    public function verifyOffline(string $digest, AnchorProof $proof): bool;
}
