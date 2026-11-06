<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Models\User;
use App\Modules\Integrity\Domain\Enums\ActivityCategory;
use App\Modules\Integrity\Domain\Enums\ActivityChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of the activity log. Insert-only (PostgreSQL rejects changes and deletes);
 * seq, previous_hash and hash are computed by the database on insert.
 *
 * @property string $id UUIDv7
 * @property int $seq
 * @property Carbon $occurred_at
 * @property ActivityCategory $category
 * @property ActivityChannel $channel
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $summary
 * @property array<string, mixed> $changes
 * @property string|null $request_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $method
 * @property string|null $route
 * @property int|null $legacy_id
 * @property string|null $previous_hash
 * @property string $hash
 * @property-read User|null $actor
 */
#[Fillable([
    'category', 'channel', 'actor_id', 'actor_name', 'actor_role', 'action', 'subject_type',
    'subject_id', 'summary', 'changes', 'request_id', 'ip', 'user_agent', 'method', 'route',
])]
class ActivityLogEntry extends Model
{
    use HasUuids;

    protected $table = 'activity_log';

    /** occurred_at is set by the database; nothing is ever updated. */
    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'category' => ActivityCategory::class,
            'channel' => ActivityChannel::class,
            'changes' => 'array',
            'legacy_id' => 'integer',
        ];
    }
}
