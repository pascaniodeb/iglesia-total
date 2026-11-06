<?php

namespace Database\Factories;

use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offering>
 */
class OfferingFactory extends Factory
{
    protected $model = Offering::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offering_date' => '2026-11-01',
            'member_id' => null,
            'offering_category_id' => OfferingCategory::factory(),
            'concept' => null,
            'amount_ves' => '0.00',
            'amount_usd' => '0.00',
            'amount_cop' => (string) fake()->numberBetween(1, 500).'000.00',
        ];
    }
}
