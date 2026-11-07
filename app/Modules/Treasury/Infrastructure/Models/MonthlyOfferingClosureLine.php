<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One category of a monthly offering closure: its offerings and the income account they were
 * posted to (the category's account at the moment of the close).
 *
 * @property string $id
 * @property string $monthly_offering_closure_id
 * @property string $offering_category_id
 * @property string $account_id
 * @property int $offerings_count
 * @property string $total_ves
 * @property string $total_usd
 * @property string $total_cop
 * @property-read OfferingCategory $category
 */
class MonthlyOfferingClosureLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * @return BelongsTo<OfferingCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(OfferingCategory::class, 'offering_category_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['offerings_count' => 'integer'];
    }
}
