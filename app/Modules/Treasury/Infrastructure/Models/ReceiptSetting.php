<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The settings of the receipt book (a single row, id 1): the number its first receipt gets.
 * Raising it begins a new physical book; the series never goes back.
 *
 * @property int $id
 * @property int $start_number
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 */
class ReceiptSetting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'start_number' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }
}
