<?php

namespace App\Modules\Treasury\Infrastructure;

use App\Modules\Accounting\Application\ReceiptHashes;
use App\Modules\Treasury\Infrastructure\Models\Receipt;

/**
 * The ReceiptHashes port backed by the receipts table. The hash is recomputed from the live row on
 * every call, so a receipt that moved behind the database guards shows up as drift in the seal.
 */
final class TreasuryReceiptHashes implements ReceiptHashes
{
    public function byEntry(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $hashes = [];

        foreach (array_chunk($entryIds, 1000) as $chunk) {
            foreach (Receipt::query()->whereIn('journal_entry_id', $chunk)->get() as $receipt) {
                $hashes[$receipt->journal_entry_id] = $receipt->content()->hash();
            }
        }

        return $hashes;
    }
}
