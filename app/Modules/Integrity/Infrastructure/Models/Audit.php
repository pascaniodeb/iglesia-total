<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of the comptroller's audit: who ran it, when, what the findings looked like at that
 * moment (the snapshot) and the notes. Insert-only: an audit is never edited or erased.
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property Carbon $ran_at
 * @property string|null $ran_by
 * @property string $status 'clean' | 'findings'
 * @property int $findings_count pending findings that count (critical and warning)
 * @property int $critical_count pending critical findings
 * @property int $acknowledged_count findings that were acknowledged when it ran
 * @property array<string, mixed> $snapshot
 * @property string|null $notes
 * @property-read User|null $runner
 */
class Audit extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** Keeps the microseconds: an imported act keeps the exact instant it had. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ran_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'snapshot' => 'array',
            'findings_count' => 'integer',
            'critical_count' => 'integer',
            'acknowledged_count' => 'integer',
        ];
    }
}
