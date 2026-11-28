<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The lifting of an acknowledgement, with the reason. A new row: the acknowledgement itself is
 * never edited, so the history of who accepted what and who withdrew it stays whole.
 *
 * @property string $id UUIDv7
 * @property string $acknowledgement_id
 * @property string $reason
 * @property string|null $revoked_by
 * @property Carbon $revoked_at
 * @property-read User|null $revoker
 */
class AuditAcknowledgementRevocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
