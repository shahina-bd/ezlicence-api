<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\User;
use Database\Factories\AvailabilityFactory;
use Database\Factories\BookingFactory;
use Database\Factories\InstructorProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->count(3)->create();
        User::factory()->instructor()->count(2)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonPath('total', 6) // 1 admin + 3 students + 2 instructors
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }

    public function test_admin_can_filter_users_by_role(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->count(2)->create();
        $instructor = User::factory()->instructor()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/users?role=instructor');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $instructor->id]);
    }

    public function test_admin_can_view_single_user(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $response = $this->actingAs($admin)->getJson("/api/admin/users/{$student->id}");

        $response->assertOk()
            ->assertJsonPath('id', $student->id)
            ->assertJsonStructure(['id', 'name', 'email', 'role']);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->getJson('/api/admin/users');

        $response->assertForbidden();
    }

    public function test_admin_can_list_all_bookings(): void
    {
        $admin = User::factory()->admin()->create();
        BookingFactory::new()->count(3)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/bookings');

        $response->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }

    public function test_admin_can_filter_bookings_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $confirmed = BookingFactory::new()->create(['status' => 'confirmed']);
        $cancelled = BookingFactory::new()->cancelled()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/bookings?status=confirmed');

        $response->assertOk()
            ->assertJsonPath('total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($confirmed->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_admin_can_update_booking_status(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = BookingFactory::new()->create(['status' => 'confirmed']);

        $response = $this->actingAs($admin)->patchJson("/api/admin/bookings/{$booking->id}/status", [
            'status' => 'completed',
        ]);

        $response->assertOk()
            ->assertJsonPath('booking.status', 'completed')
            ->assertJsonPath('message', 'Booking status updated successfully.');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'completed',
        ]);
    }

    public function test_admin_cancelling_booking_releases_availability_slot(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $start = now()->addDays(5)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $slot = AvailabilityFactory::new()->unavailable()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start,
            'end_at' => $end,
        ]);

        $booking = BookingFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/bookings/{$booking->id}/status", [
            'status' => 'cancelled',
        ]);

        $response->assertOk()
            ->assertJsonPath('booking.status', 'cancelled');

        $this->assertDatabaseHas('availabilities', [
            'id' => $slot->id,
            'is_available' => true,
        ]);
    }
}
