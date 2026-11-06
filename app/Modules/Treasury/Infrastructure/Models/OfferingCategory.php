<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use Database\Factories\OfferingCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A category of offering and the income account its money goes to. The account belongs to
 * Accounting: Treasury knows it by id and reads it through the ChartOfAccounts contract.
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $name
 * @property string $account_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'account_id'])]
#[UseFactory(OfferingCategoryFactory::class)]
class OfferingCategory extends Model
{
    /** @use HasFactory<OfferingCategoryFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
