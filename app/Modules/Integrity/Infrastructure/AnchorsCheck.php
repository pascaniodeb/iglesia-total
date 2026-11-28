<?php

namespace App\Modules\Integrity\Infrastructure;

use App\Modules\Integrity\Application\Anchoring\ChainAnchoring;
use App\Modules\Integrity\Domain\IntegrityCheck;

/**
 * What was anchored outside the church is still in the chain, and every confirmed proof still
 * corresponds to what it says it proves. Checked with no network.
 */
final class AnchorsCheck implements IntegrityCheck
{
    public function __construct(private readonly ChainAnchoring $anchoring) {}

    public function id(): string
    {
        return 'anchors.external';
    }

    public function title(): string
    {
        return __('What was anchored outside the church is still in the chains and its proofs hold');
    }

    public function findings(): array
    {
        $result = $this->anchoring->verify();
        $findings = [];

        foreach ($result['rewritten'] as $label) {
            $findings[] = __('The anchored block :anchor is no longer in its chain with that hash: history was rewritten.', ['anchor' => $label]);
        }

        foreach ($result['invalid'] as $label) {
            $findings[] = __('The proof of the anchor :anchor does not correspond to what it anchors.', ['anchor' => $label]);
        }

        return $findings;
    }
}
