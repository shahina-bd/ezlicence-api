<?php

namespace Tests\Feature;

use App\Models\InstructorProfile;
use App\Models\User;
use Database\Factories\AvailabilityFactory;
use Database\Factories\BookingFactory;
use Database\Factories\InstructorProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityAndValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_routes_always_return_json_errors_even_without_accept_header(): void
    {
        // 401 Unauthenticated without Accept: application/json
        $unauth = $this->get('/api/me');
        $unauth->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');

        // 404 Not Found without Accept: application/json
        $notFound = $this->get('/api/instructors/999999');
        $notFound->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_mass_assignment_protection_on_booking_creation(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create([
            'approved' => true,
            'hourly_rate' => 45.00,
        ]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => (clone $start)->subHour(),
            'end_at' => (clone $end)->addHour(),
            'is_available' => true,
        ]);

        // Attempt to spoof student_id, total_price, status, and payment_status
        $response = $this->actingAs($studentA)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'pickup_postcode' => 'M1 1AE',
            'student_id' => $studentB->id,
            'total_price' => '0.01',
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $response->assertCreated()
            ->assertJsonPath('booking.student_id', $studentA->id)
            ->assertJsonPath('booking.total_price', '90.00')
            ->assertJsonPath('booking.status', 'pending')
            ->assertJsonPath('booking.payment_status', 'unpaid');
    }

    public function test_mass_assignment_protection_on_instructor_profile_model(): void
    {
        $user = User::factory()->instructor()->create();

        $profile = new InstructorProfile([
            'phone' => '07123456789',
            'postcode' => 'M1 1AE',
            'transmission' => 'manual',
            'hourly_rate' => 35.00,
            'approved' => true,
            'user_id' => 999,
        ]);

        $this->assertNull($profile->approved);
        $this->assertNull($profile->user_id);
    }

    public function test_input_validation_rejects_invalid_query_filters(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        // Invalid transmission filter on public instructors list
        $this->getJson('/api/instructors?transmission=spaceship')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transmission']);

        // Invalid status filter on student bookings
        $this->actingAs($student)->getJson('/api/bookings/my?status=invalid_status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Invalid role filter on admin users endpoint
        $this->actingAs($admin)->getJson('/api/admin/users?role=superadmin')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        // Invalid status filter on admin bookings endpoint
        $this->actingAs($admin)->getJson('/api/admin/bookings?status=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_role_permissions_prevent_privilege_escalation(): void
    {
        $student = User::factory()->student()->create();
        $instructorUser = User::factory()->instructor()->create();
        $unapprovedProfile = InstructorProfileFactory::new()->unapproved()->create([
            'user_id' => $instructorUser->id,
        ]);

        $booking = BookingFactory::new()->pending()->create([
            'student_id' => $student->id,
            'instructor_profile_id' => $unapprovedProfile->id,
            'start_at' => now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(3)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        // Unapproved instructor cannot confirm or reject bookings
        $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/confirm")
            ->assertForbidden();

        $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/reject")
            ->assertForbidden();

        // Student cannot access instructor bookings endpoint
        $this->actingAs($student)->getJson('/api/instructor/bookings')
            ->assertForbidden();

        // Instructor cannot access admin endpoints
        $this->actingAs($instructorUser)->getJson('/api/admin/users')
            ->assertForbidden();

        $this->actingAs($instructorUser)->getJson('/api/admin/bookings')
            ->assertForbidden();
    }

    public function test_auth_endpoints_are_rate_limited(): void
    {
        RateLimiter::clear('auth');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'nonexistent@example.com',
                'password' => 'wrongpassword',
            ])->assertStatus(401);
        }

        // 11th attempt within a minute should be throttled (429 Too Many Requests)
        $this->postJson('/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword',
        ])->assertStatus(429);
    }
}
