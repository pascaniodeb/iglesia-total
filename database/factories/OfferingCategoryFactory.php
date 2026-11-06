<?php

namespace Database\Factories;

use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferingCategory>
 */
class OfferingCategoryFactory extends Factory
{
    protected $model = OfferingCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ofrenda '.fake()->unique()->word(),
            'account_id' => AccountingAccount::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
