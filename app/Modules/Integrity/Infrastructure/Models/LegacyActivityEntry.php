<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An entry of the old system's activity log, archived verbatim. Insert-only.
 *
 * @property string $id UUIDv7
 * @property int $legacy_id
 * @property Carbon $occurred_at
 * @property int|null $legacy_actor_id
 * @property string $actor_name
 * @property string $actor_role
 * @property string $action
 * @property string $entity
 * @property string|null $entity_id
 * @property string $summary
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed>|null $diff
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $archived_at
 */
class LegacyActivityEntry extends Model
{
    use HasUuids;

    protected $table = 'legacy_activity_log';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'archived_at' => 'datetime', 'before' => 'array', 'after' => 'array', 'diff' => 'array'];
    }
}
