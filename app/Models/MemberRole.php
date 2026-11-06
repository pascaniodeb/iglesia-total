<?php

namespace App\Models;

use Database\Factories\MemberRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A position held by a member (deacon, usher...) with the period it was held.
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $member_id
 * @property string $role
 * @property Carbon|null $since
 * @property Carbon|null $until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['member_id', 'role', 'since', 'until'])]
class MemberRole extends Model
{
    /** @use HasFactory<MemberRoleFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'since' => 'date',
            'until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
