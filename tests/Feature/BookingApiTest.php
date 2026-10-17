<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\User;
use Database\Factories\AvailabilityFactory;
use Database\Factories\BookingFactory;
use Database\Factories\InstructorProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_a_booking(): void
    {
        $student = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create([
            'approved' => true,
            'hourly_rate' => 40.00,
        ]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        // Instructor must have availability
        AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => (clone $start)->subHour(),
            'end_at' => (clone $end)->addHour(),
            'is_available' => true,
        ]);

        $response = $this->actingAs($student)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'pickup_postcode' => 'sw1a 1aa',
        ]);

        $response->assertCreated()
            ->assertJsonPath('booking.student_id', $student->id)
            ->assertJsonPath('booking.instructor_profile_id', $instructor->id)
            ->assertJsonPath('booking.pickup_postcode', 'SW1A 1AA')
            ->assertJsonPath('booking.total_price', '80.00')
            ->assertJsonPath('booking.status', 'pending')
            ->assertJsonPath('booking.payment_status', 'unpaid');

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'instructor_profile_id' => $instructor->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_cannot_book_when_instructor_has_no_availability(): void
    {
        $student = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $response = $this->actingAs($student)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'pickup_postcode' => 'SW1A 1AA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_at']);
    }

    public function test_cannot_book_unapproved_instructor(): void
    {
        $student = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->unapproved()->create();

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $response = $this->actingAs($student)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'pickup_postcode' => 'SW1A 1AA',
        ]);

        $response->assertNotFound();
    }

    public function test_cannot_double_book_overlapping_slot(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(3);

        AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => (clone $start)->subHour(),
            'end_at' => (clone $end)->addHour(),
            'is_available' => true,
        ]);

        // First booking: 10:00 - 12:00
        $this->actingAs($studentA)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start->toDateTimeString(),
            'end_at' => (clone $start)->addHours(2)->toDateTimeString(),
            'pickup_postcode' => 'SW1A 1AA',
        ])->assertCreated();

        // Second booking attempts overlap: 11:00 - 13:00
        $response = $this->actingAs($studentB)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => (clone $start)->addHour()->toDateTimeString(),
            'end_at' => (clone $end)->toDateTimeString(),
            'pickup_postcode' => 'SW1A 1AA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_at']);
    }

    public function test_lesson_duration_must_be_whole_hours(): void
    {
        $student = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addMinutes(90); // 1.5 hours

        AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => (clone $start)->subHour(),
            'end_at' => (clone $end)->addHour(),
            'is_available' => true,
        ]);

        $response = $this->actingAs($student)->postJson('/api/bookings', [
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'pickup_postcode' => 'SW1A 1AA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_at']);
    }

    public function test_instructor_cannot_create_booking(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $targetInstructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $response = $this->actingAs($instructorUser)->postJson('/api/bookings', [
            'instructor_profile_id' => $targetInstructor->id,
            'start_at' => now()->addDays(2)->toDateTimeString(),
            'end_at' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            'pickup_postcode' => 'SW1A 1AA',
        ]);

        $response->assertForbidden();
    }

    public function test_student_can_view_my_bookings(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $booking = BookingFactory::new()->create([
            'student_id' => $student->id,
        ]);
        $otherBooking = BookingFactory::new()->create([
            'student_id' => $otherStudent->id,
        ]);

        $response = $this->actingAs($student)->getJson('/api/bookings/my');

        $response->assertOk()
            ->assertJsonFragment(['id' => $booking->id])
            ->assertJsonMissing(['id' => $otherBooking->id]);
    }

    public function test_authorized_user_can_view_single_booking(): void
    {
        $student = User::factory()->student()->create();
        $booking = BookingFactory::new()->create([
            'student_id' => $student->id,
        ]);

        $response = $this->actingAs($student)->getJson("/api/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonPath('booking.id', $booking->id);
    }

    public function test_unauthorized_user_cannot_view_booking(): void
    {
        $student = User::factory()->student()->create();
        $otherUser = User::factory()->student()->create();
        $booking = BookingFactory::new()->create([
            'student_id' => $student->id,
        ]);

        $response = $this->actingAs($otherUser)->getJson("/api/bookings/{$booking->id}");

        $response->assertForbidden();
    }

    public function test_student_can_cancel_booking_in_advance(): void
    {
        $student = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $booking = BookingFactory::new()->create([
            'student_id' => $student->id,
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($student)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('booking.status', 'cancelled');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_student_cannot_cancel_booking_within_24_hours(): void
    {
        $student = User::factory()->student()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        // Lesson is 10 hours from now
        $start = now()->addHours(10);
        $end = (clone $start)->addHours(2);

        $booking = BookingFactory::new()->create([
            'student_id' => $student->id,
            'instructor_profile_id' => $instructor->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($student)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Bookings cannot be cancelled less than 24 hours before the lesson start time.');
    }

    public function test_instructor_can_cancel_booking(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $instructor = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $booking = BookingFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'status' => 'pending',
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('booking.status', 'cancelled');
    }

    public function test_cannot_cancel_already_cancelled_booking(): void
    {
        $student = User::factory()->student()->create();
        $booking = BookingFactory::new()->cancelled()->create([
            'student_id' => $student->id,
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
        ]);

        $response = $this->actingAs($student)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This booking has already been cancelled.');
    }

    public function test_student_cannot_cancel_past_booking(): void
    {
        $student = User::factory()->student()->create();
        $booking = BookingFactory::new()->create([
            'student_id' => $student->id,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHours(2),
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($student)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Past bookings cannot be cancelled.');
    }

    public function test_student_cannot_cancel_completed_booking(): void
    {
        $student = User::factory()->student()->create();
        $booking = BookingFactory::new()->completed()->create([
            'student_id' => $student->id,
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
        ]);

        $response = $this->actingAs($student)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Completed bookings cannot be cancelled.');
    }

    public function test_student_cannot_cancel_another_students_booking(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();

        $booking = BookingFactory::new()->create([
            'student_id' => $studentA->id,
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($studentB)->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertForbidden();
    }

    public function test_instructor_can_view_and_filter_their_bookings(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $otherProfile = InstructorProfileFactory::new()->create(['approved' => true]);

        $pendingBooking = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profile->id,
        ]);
        $confirmedBooking = BookingFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'status' => 'confirmed',
        ]);
        $otherInstructorBooking = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $otherProfile->id,
        ]);

        // View all instructor bookings
        $response = $this->actingAs($instructorUser)->getJson('/api/instructor/bookings');
        $response->assertOk()
            ->assertJsonPath('total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($pendingBooking->id, $ids);
        $this->assertContains($confirmedBooking->id, $ids);
        $this->assertNotContains($otherInstructorBooking->id, $ids);

        // Filter by pending status
        $filtered = $this->actingAs($instructorUser)->getJson('/api/instructor/bookings?status=pending');
        $filtered->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $pendingBooking->id);
    }

    public function test_instructor_can_confirm_pending_booking(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $slot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
            'is_available' => true,
        ]);

        $booking = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/confirm");

        $response->assertOk()
            ->assertJsonPath('message', 'Booking confirmed successfully.')
            ->assertJsonPath('booking.status', 'confirmed');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('availabilities', [
            'id' => $slot->id,
            'is_available' => false,
        ]);
    }

    public function test_instructor_cannot_confirm_another_instructors_booking(): void
    {
        $instructorA = User::factory()->instructor()->create();
        InstructorProfileFactory::new()->create(['user_id' => $instructorA->id, 'approved' => true]);

        $instructorB = User::factory()->instructor()->create();
        $profileB = InstructorProfileFactory::new()->create(['user_id' => $instructorB->id, 'approved' => true]);

        $booking = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profileB->id,
        ]);

        $response = $this->actingAs($instructorA)->patchJson("/api/bookings/{$booking->id}/confirm");

        $response->assertForbidden();
    }

    public function test_student_cannot_confirm_booking(): void
    {
        $student = User::factory()->student()->create();
        $booking = BookingFactory::new()->pending()->create([
            'student_id' => $student->id,
        ]);

        $response = $this->actingAs($student)->patchJson("/api/bookings/{$booking->id}/confirm");

        $response->assertForbidden();
    }

    public function test_instructor_cannot_confirm_non_pending_booking(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $booking = BookingFactory::new()->cancelled()->create([
            'instructor_profile_id' => $profile->id,
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/confirm");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only pending bookings can be confirmed.');
    }

    public function test_instructor_can_reject_pending_booking_and_release_slot(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $start = now()->addDays(4)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $slot = AvailabilityFactory::new()->unavailable()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
        ]);

        $booking = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/reject");

        $response->assertOk()
            ->assertJsonPath('message', 'Booking rejected successfully.')
            ->assertJsonPath('booking.status', 'rejected');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('availabilities', [
            'id' => $slot->id,
            'is_available' => true,
        ]);
    }

    public function test_instructor_cannot_reject_confirmed_booking(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $booking = BookingFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/reject");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only pending bookings can be rejected.');
    }

    public function test_instructor_can_mark_confirmed_booking_as_completed(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $booking = BookingFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$booking->id}/complete");

        $response->assertOk()
            ->assertJsonPath('message', 'Booking marked as completed.')
            ->assertJsonPath('booking.status', 'completed');
    }

    public function test_instructor_can_confirm_and_reject_via_booking_status_endpoint(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $bookingToConfirm = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(3)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        $bookingToReject = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => now()->addDays(4)->setHour(10)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(4)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        $confirmRes = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$bookingToConfirm->id}/status", [
            'status' => 'confirmed',
        ]);
        $confirmRes->assertOk()
            ->assertJsonPath('booking.status', 'confirmed');

        $rejectRes = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$bookingToReject->id}/status", [
            'status' => 'rejected',
        ]);
        $rejectRes->assertOk()
            ->assertJsonPath('booking.status', 'rejected');
    }

    public function test_cannot_confirm_second_overlapping_booking(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $instructorUser->id,
            'approved' => true,
        ]);

        $start = now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        BookingFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'confirmed',
        ]);

        $conflictingPending = BookingFactory::new()->pending()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => (clone $start)->addHour(),
            'end_at' => (clone $end)->addHour(),
        ]);

        $response = $this->actingAs($instructorUser)->patchJson("/api/bookings/{$conflictingPending->id}/confirm");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_at']);
    }
}

