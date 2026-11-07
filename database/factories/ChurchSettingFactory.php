<?php

namespace Database\Factories;

use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChurchSetting>
 */
class ChurchSettingFactory extends Factory
{
    protected $model = ChurchSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 1,
            'name' => 'Iglesia Cordero Central',
            'pastor' => 'Pastor Pablo Ascanio',
            'code' => 'M07A1985C0001',
            'rif' => 'J-002288007',
            'denomination' => 'Iglesia Pentecostal Unida de Venezuela',
            'headquarters' => 'Sede Nacional Caracas',
            'registration_note' => 'Registro 99564 DG 520 DF 11016',
            'denomination_rif' => 'J-00228900-7',
        ];
    }
}
