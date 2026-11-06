<?php

namespace App\Modules\Members\Infrastructure\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Database\Factories\MemberChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $member_id
 * @property string $name
 * @property Carbon|null $birth_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['member_id', 'name', 'birth_date'])]
#[UseFactory(MemberChildFactory::class)]
class MemberChild extends Model
{
    /** @use HasFactory<MemberChildFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
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
