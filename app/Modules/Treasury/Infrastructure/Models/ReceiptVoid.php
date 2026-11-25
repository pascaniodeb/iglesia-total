<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The voiding of a receipt: why, who, when and the reversal entry that undid its posting.
 * Insert-only, one per receipt.
 *
 * @property string $id UUIDv7
 * @property string $receipt_id
 * @property string $reason
 * @property string $reversal_entry_id
 * @property string|null $voided_by
 * @property Carbon $voided_at
 * @property-read Receipt $receipt
 */
class ReceiptVoid extends Model
{
    use HasUuids;

    public const CREATED_AT = 'voided_at';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
