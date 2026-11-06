<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberRole>
 */
class MemberRoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'role' => fake()->randomElement(['Deacon', 'Usher', 'Teacher', 'Treasurer']),
            'since' => fake()->dateTimeBetween('-5 years', '-1 year'),
            'until' => null,
        ];
    }
}
