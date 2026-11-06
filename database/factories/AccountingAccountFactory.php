<?php

namespace Database\Factories;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingAccount>
 */
class AccountingAccountFactory extends Factory
{
    protected $model = AccountingAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'I-'.fake()->unique()->numberBetween(100, 499),
            'name' => mb_strtoupper(fake()->sentence(3, false)),
            'type' => AccountType::Income,
            'is_active' => true,
        ];
    }

    public function expense(): static
    {
        return $this->state(fn () => [
            'code' => 'E-'.fake()->unique()->numberBetween(500, 999),
            'type' => AccountType::Expense,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
