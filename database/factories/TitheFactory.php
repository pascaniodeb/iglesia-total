<?php

namespace Database\Factories;

use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tithe>
 */
class TitheFactory extends Factory
{
    protected $model = Tithe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tithe_date' => '2026-11-01',
            'member_id' => Member::factory(),
            'amount_ves' => '0.00',
            'amount_usd' => '0.00',
            'amount_cop' => (string) fake()->numberBetween(1, 500).'000.00',
            'notes' => null,
        ];
    }
}
