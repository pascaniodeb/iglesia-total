<?php

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The support of a movement (receipt number and/or a private file with its SHA-256). Insert-only.
 *
 * @property string $id
 * @property string $journal_entry_id
 * @property string|null $receipt_number
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $uploaded_by
 * @property Carbon $created_at
 */
class JournalEntrySupport extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
