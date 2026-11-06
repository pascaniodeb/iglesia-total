<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberChild;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberChild>
 */
class MemberChildFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'name' => fake()->firstName(),
            'birth_date' => fake()->dateTimeBetween('-18 years'),
        ];
    }
}
