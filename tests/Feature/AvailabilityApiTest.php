<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\InstructorProfile;
use App\Models\User;
use Database\Factories\AvailabilityFactory;
use Database\Factories\InstructorProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_user_can_view_approved_instructor_availability(): void
    {
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);
        $slot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/instructors/{$instructor->id}/availability");

        $response->assertOk()
            ->assertJsonFragment(['id' => $slot->id]);
    }

    public function test_unapproved_instructor_availability_returns_404(): void
    {
        $instructor = InstructorProfileFactory::new()->unapproved()->create();
        AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/instructors/{$instructor->id}/availability");

        $response->assertNotFound();
    }

    public function test_only_available_and_future_slots_are_returned_publicly(): void
    {
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);
        $futureAvailableSlot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'is_available' => true,
        ]);
        $unavailableSlot = AvailabilityFactory::new()->unavailable()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
        ]);
        $pastSlot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $instructor->id,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHours(2),
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/instructors/{$instructor->id}/availability");

        $response->assertOk()
            ->assertJsonFragment(['id' => $futureAvailableSlot->id])
            ->assertJsonMissing(['id' => $unavailableSlot->id])
            ->assertJsonMissing(['id' => $pastSlot->id]);
    }

    public function test_authenticated_instructor_can_add_availability(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
            'approved' => true,
        ]);

        $start = now()->addDays(2)->setHour(9)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $response = $this->actingAs($user)->postJson('/api/instructor/availability', [
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('availability.instructor_profile_id', $profile->id)
            ->assertJsonPath('availability.is_available', true);

        $this->assertDatabaseHas('availabilities', [
            'instructor_profile_id' => $profile->id,
            'is_available' => true,
        ]);
    }

    public function test_unapproved_instructor_cannot_add_availability(): void
    {
        $user = User::factory()->instructor()->create();
        InstructorProfileFactory::new()->unapproved()->create([
            'user_id' => $user->id,
        ]);

        $start = now()->addDays(2)->setHour(9)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        $response = $this->actingAs($user)->postJson('/api/instructor/availability', [
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
        ]);

        $response->assertForbidden();
    }

    public function test_cannot_add_overlapping_availability(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
            'approved' => true,
        ]);

        $start = now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
        ]);

        // Attempt overlap
        $response = $this->actingAs($user)->postJson('/api/instructor/availability', [
            'start_at' => (clone $start)->addHour()->toDateTimeString(),
            'end_at' => (clone $end)->addHour()->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'This time overlaps an existing availability slot.']);
    }

    public function test_student_cannot_add_availability(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->postJson('/api/instructor/availability', [
            'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDay()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertForbidden();
    }

    public function test_instructor_cannot_delete_another_instructors_slot(): void
    {
        $userA = User::factory()->instructor()->create();
        InstructorProfileFactory::new()->create(['user_id' => $userA->id]);

        $userB = User::factory()->instructor()->create();
        $profileB = InstructorProfileFactory::new()->create(['user_id' => $userB->id]);

        $slotB = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profileB->id,
        ]);

        $response = $this->actingAs($userA)->deleteJson("/api/instructor/availability/{$slotB->id}");

        $response->assertForbidden();
    }

    public function test_instructor_can_delete_their_slot(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
        ]);
        $slot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/instructor/availability/{$slot->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Availability deleted successfully.']);

        $this->assertDatabaseMissing('availabilities', [
            'id' => $slot->id,
        ]);
    }

    public function test_cannot_add_availability_overlapping_existing_booking(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
            'approved' => true,
        ]);

        $start = now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(2);

        // Pre-existing booking
        \Database\Factories\BookingFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'confirmed',
        ]);

        // Attempt to create availability slot over booking
        $response = $this->actingAs($user)->postJson('/api/instructor/availability', [
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'This time overlaps an existing booking.']);
    }

    public function test_instructor_can_update_their_availability_slot(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
            'approved' => true,
        ]);

        $slot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => now()->addDays(2)->setHour(9)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(2)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        $newStart = now()->addDays(2)->setHour(13)->setMinute(0)->setSecond(0);
        $newEnd = now()->addDays(2)->setHour(17)->setMinute(0)->setSecond(0);

        $response = $this->actingAs($user)->putJson("/api/instructor/availability/{$slot->id}", [
            'start_at' => $newStart->toDateTimeString(),
            'end_at' => $newEnd->toDateTimeString(),
        ]);

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Availability updated successfully.']);

        $this->assertDatabaseHas('availabilities', [
            'id' => $slot->id,
            'start_at' => $newStart->toDateTimeString(),
            'end_at' => $newEnd->toDateTimeString(),
        ]);
    }

    public function test_cannot_update_availability_to_overlap_existing_slot(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
            'approved' => true,
        ]);

        $slot1 = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => now()->addDays(2)->setHour(9)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(2)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        $slot2 = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(2)->setHour(17)->setMinute(0)->setSecond(0),
        ]);

        // Attempt to update slot2 to overlap slot1
        $response = $this->actingAs($user)->putJson("/api/instructor/availability/{$slot2->id}", [
            'start_at' => now()->addDays(2)->setHour(11)->setMinute(0)->setSecond(0)->toDateTimeString(),
            'end_at' => now()->addDays(2)->setHour(15)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'This time overlaps an existing availability slot.']);
    }

    public function test_instructor_cannot_update_another_instructors_availability(): void
    {
        $userA = User::factory()->instructor()->create();
        $profileA = InstructorProfileFactory::new()->create(['user_id' => $userA->id, 'approved' => true]);

        $userB = User::factory()->instructor()->create();
        $profileB = InstructorProfileFactory::new()->create(['user_id' => $userB->id, 'approved' => true]);

        $slotA = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profileA->id,
            'start_at' => now()->addDays(2)->setHour(9)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(2)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        $response = $this->actingAs($userB)->putJson("/api/instructor/availability/{$slotA->id}", [
            'start_at' => now()->addDays(2)->setHour(13)->setMinute(0)->setSecond(0)->toDateTimeString(),
            'end_at' => now()->addDays(2)->setHour(15)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ]);

        $response->assertForbidden();
    }

    public function test_instructor_can_view_own_availability_slots(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create(['user_id' => $user->id, 'approved' => true]);

        $slot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => now()->addDays(2)->setHour(9)->setMinute(0)->setSecond(0),
            'end_at' => now()->addDays(2)->setHour(12)->setMinute(0)->setSecond(0),
        ]);

        $response = $this->actingAs($user)->getJson('/api/instructor/availability');

        $response->assertOk()
            ->assertJsonFragment(['id' => $slot->id]);
    }

    public function test_past_slots_cannot_be_created(): void
    {
        $user = User::factory()->instructor()->create();
        InstructorProfileFactory::new()->create(['user_id' => $user->id, 'approved' => true]);

        $response = $this->actingAs($user)->postJson('/api/instructor/availability', [
            'start_at' => now()->subDays(1)->setHour(9)->setMinute(0)->setSecond(0)->toDateTimeString(),
            'end_at' => now()->subDays(1)->setHour(12)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_at']);
    }

    public function test_existing_bookings_are_protected_when_availability_is_updated_or_deleted(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create(['user_id' => $user->id, 'approved' => true]);

        $start = now()->addDays(3)->setHour(9)->setMinute(0)->setSecond(0);
        $end = (clone $start)->addHours(6);

        $slot = AvailabilityFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => $start,
            'end_at' => $end,
        ]);

        \Database\Factories\BookingFactory::new()->create([
            'instructor_profile_id' => $profile->id,
            'start_at' => (clone $start)->addHour(),
            'end_at' => (clone $start)->addHours(3),
            'status' => 'confirmed',
        ]);

        // Attempt to update slot that has a confirmed booking
        $updateResponse = $this->actingAs($user)->putJson("/api/instructor/availability/{$slot->id}", [
            'start_at' => (clone $start)->addHours(4)->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
        ]);

        $updateResponse->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot update availability slot because it has an existing booking.']);

        // Attempt to delete slot that has a confirmed booking
        $deleteResponse = $this->actingAs($user)->deleteJson("/api/instructor/availability/{$slot->id}");

        $deleteResponse->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot delete availability slot with an existing booking.']);

        $this->assertDatabaseHas('availabilities', ['id' => $slot->id]);
    }
}
