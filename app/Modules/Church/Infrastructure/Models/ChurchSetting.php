<?php

namespace App\Modules\Church\Infrastructure\Models;

use Database\Factories\ChurchSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The sole mutable identity record of the congregation.
 *
 * @property int $id
 * @property string $name
 * @property string $pastor
 * @property string $code
 * @property string $rif
 * @property string $denomination
 * @property string $headquarters
 * @property string $registration_note
 * @property string $denomination_rif
 * @property string|null $logo_light_path
 * @property string|null $logo_light_mime
 * @property int|null $logo_light_size_bytes
 * @property string|null $logo_light_sha256
 * @property string|null $logo_dark_path
 * @property string|null $logo_dark_mime
 * @property int|null $logo_dark_size_bytes
 * @property string|null $logo_dark_sha256
 * @property string|null $favicon_path
 * @property string|null $favicon_mime
 * @property int|null $favicon_size_bytes
 * @property string|null $favicon_sha256
 * @property string|null $updated_by
 * @property Carbon $updated_at
 */
#[UseFactory(ChurchSettingFactory::class)]
class ChurchSetting extends Model
{
    /** @use HasFactory<ChurchSettingFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'logo_light_size_bytes' => 'integer',
            'logo_dark_size_bytes' => 'integer',
            'favicon_size_bytes' => 'integer',
        ];
    }
}
