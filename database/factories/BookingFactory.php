<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(2, 14))->setHour(14)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        return [
            'student_id' => User::factory()->student(),
            'instructor_profile_id' => InstructorProfileFactory::new(),
            'start_at' => $start,
            'end_at' => $end,
            'pickup_postcode' => 'SW1A 1AA',
            'total_price' => 90.00,
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}
