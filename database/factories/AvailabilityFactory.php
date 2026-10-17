<?php

namespace Database\Factories;

use App\Models\Availability;
use App\Models\InstructorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 14))->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        return [
            'instructor_profile_id' => InstructorProfileFactory::new(),
            'start_at' => $start,
            'end_at' => $end,
            'is_available' => true,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }
}
