<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * "This finding, with this data, was reviewed, and here is why." Tied to the fingerprint of the
 * finding: change the data and it no longer applies. Insert-only; revoking is a new row.
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $fingerprint
 * @property string $check_id
 * @property string $subject
 * @property string $detail
 * @property string $reason
 * @property string|null $acknowledged_by
 * @property Carbon $acknowledged_at
 * @property-read User|null $acknowledger
 * @property-read AuditAcknowledgementRevocation|null $revocation
 */
class AuditAcknowledgement extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** Keeps the microseconds: an imported act keeps the exact instant it had. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return HasOne<AuditAcknowledgementRevocation, $this>
     */
    public function revocation(): HasOne
    {
        return $this->hasOne(AuditAcknowledgementRevocation::class, 'acknowledgement_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
