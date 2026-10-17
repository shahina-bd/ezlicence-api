<?php

namespace Database\Factories;

use App\Models\InstructorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorProfile>
 */
class InstructorProfileFactory extends Factory
{
    protected $model = InstructorProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->instructor(),
            'phone' => '07' . fake()->numerify('#########'),
            'bio' => fake()->paragraph(),
            'profile_image' => 'https://example.com/profiles/avatar.jpg',
            'postcode' => 'SW1A 1AA',
            'city' => 'London',
            'transmission' => fake()->randomElement(['manual', 'automatic']),
            'hourly_rate' => 45.00,
            'approved' => true,
        ];
    }

    public function unapproved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved' => false,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'transmission' => 'manual',
        ]);
    }

    public function automatic(): static
    {
        return $this->state(fn (array $attributes) => [
            'transmission' => 'automatic',
        ]);
    }
}
