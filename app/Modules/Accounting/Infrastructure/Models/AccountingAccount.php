<?php

namespace App\Modules\Accounting\Infrastructure\Models;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use Database\Factories\AccountingAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An account of the chart. Never deleted; its code, type and function never change (enforced by
 * the database).
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property SystemFunction|null $system_function
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
#[UseFactory(AccountingAccountFactory::class)]
class AccountingAccount extends Model
{
    /** @use HasFactory<AccountingAccountFactory> */
    use HasFactory;

    use HasUuids;

    public static function system(SystemFunction $function): self
    {
        return self::query()->where('system_function', $function->value)->sole();
    }

    public function isSystem(): bool
    {
        return $this->system_function !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrderedByCode(Builder $query): void
    {
        $query->orderBy('code')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'type' => AccountType::class,
            'system_function' => SystemFunction::class,
            'is_active' => 'boolean',
        ];
    }
}
