<?php

namespace Database\Factories;

use App\Enums\MemberMaritalStatus;
use App\Enums\MemberSex;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * Define the model's default state. Only the name is required, like the real registry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
        ];
    }

    /**
     * A member with the full registry card filled in.
     */
    public function complete(): static
    {
        return $this->state(fn () => [
            'sex' => fake()->randomElement(MemberSex::cases()),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years'),
            'phone' => fake()->numerify('04##-#######'),
            'email' => fake()->unique()->safeEmail(),
            'marital_status' => fake()->randomElement(MemberMaritalStatus::cases()),
            'joined_date' => fake()->dateTimeBetween('-10 years'),
            'status' => MemberStatus::Active,
        ]);
    }

    public function withStatus(MemberStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
