<?php

namespace Tests\Feature;

use App\Models\InstructorProfile;
use App\Models\User;
use Database\Factories\InstructorProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_user_can_view_approved_instructors_list(): void
    {
        $approvedInstructor = InstructorProfileFactory::new()->create(['approved' => true]);
        $unapprovedInstructor = InstructorProfileFactory::new()->create(['approved' => false]);

        $response = $this->getJson('/api/instructors');

        $response->assertOk()
            ->assertJsonFragment(['id' => $approvedInstructor->id])
            ->assertJsonMissing(['id' => $unapprovedInstructor->id]);
    }

    public function test_public_user_receives_paginated_approved_instructors_with_12_per_page(): void
    {
        InstructorProfileFactory::new()->count(15)->create(['approved' => true]);

        $response = $this->getJson('/api/instructors');

        $response->assertOk()
            ->assertJsonPath('per_page', 12)
            ->assertJsonPath('total', 15)
            ->assertJsonCount(12, 'data');
    }

    public function test_public_user_can_search_approved_instructors_by_uk_postcode(): void
    {
        $instructorInLondon = InstructorProfileFactory::new()->create([
            'postcode' => 'SW1A 1AA',
            'approved' => true,
        ]);

        $instructorInManchester = InstructorProfileFactory::new()->create([
            'postcode' => 'M1 1AE',
            'approved' => true,
        ]);

        // Exact match with space
        $res1 = $this->getJson('/api/instructors?postcode=SW1A 1AA');
        $res1->assertOk()
            ->assertJsonFragment(['id' => $instructorInLondon->id])
            ->assertJsonMissing(['id' => $instructorInManchester->id]);

        // Outward code (district) prefix search
        $res2 = $this->getJson('/api/instructors?postcode=SW1A');
        $res2->assertOk()
            ->assertJsonFragment(['id' => $instructorInLondon->id])
            ->assertJsonMissing(['id' => $instructorInManchester->id]);

        // Case-insensitive search
        $res3 = $this->getJson('/api/instructors?postcode=sw1a');
        $res3->assertOk()
            ->assertJsonFragment(['id' => $instructorInLondon->id])
            ->assertJsonMissing(['id' => $instructorInManchester->id]);

        // Outward code for Manchester
        $res4 = $this->getJson('/api/instructors?postcode=m1');
        $res4->assertOk()
            ->assertJsonFragment(['id' => $instructorInManchester->id])
            ->assertJsonMissing(['id' => $instructorInLondon->id]);
    }

    public function test_unapproved_instructors_are_excluded_from_postcode_search(): void
    {
        $unapprovedInstructor = InstructorProfileFactory::new()->create([
            'postcode' => 'SW1A 1AA',
            'approved' => false,
        ]);

        $response = $this->getJson('/api/instructors?postcode=SW1A');

        $response->assertOk()
            ->assertJsonMissing(['id' => $unapprovedInstructor->id]);
    }

    public function test_filter_instructors_by_manual_transmission(): void
    {
        $manual = InstructorProfileFactory::new()->create([
            'transmission' => 'manual',
            'approved' => true,
        ]);

        $automatic = InstructorProfileFactory::new()->create([
            'transmission' => 'automatic',
            'approved' => true,
        ]);

        $response = $this->getJson('/api/instructors?transmission=manual');

        $response->assertOk()
            ->assertJsonFragment(['id' => $manual->id])
            ->assertJsonMissing(['id' => $automatic->id]);
    }

    public function test_filter_instructors_by_automatic_transmission(): void
    {
        $manual = InstructorProfileFactory::new()->create([
            'transmission' => 'manual',
            'approved' => true,
        ]);

        $automatic = InstructorProfileFactory::new()->create([
            'transmission' => 'automatic',
            'approved' => true,
        ]);

        $response = $this->getJson('/api/instructors?transmission=automatic');

        $response->assertOk()
            ->assertJsonFragment(['id' => $automatic->id])
            ->assertJsonMissing(['id' => $manual->id]);
    }

    public function test_filter_by_both_postcode_and_transmission(): void
    {
        $target = InstructorProfileFactory::new()->create([
            'postcode' => 'SW1A 1AA',
            'transmission' => 'manual',
            'approved' => true,
        ]);

        $otherPostcode = InstructorProfileFactory::new()->create([
            'postcode' => 'M1 1AE',
            'transmission' => 'manual',
            'approved' => true,
        ]);

        $otherTransmission = InstructorProfileFactory::new()->create([
            'postcode' => 'SW1A 1AA',
            'transmission' => 'automatic',
            'approved' => true,
        ]);

        $response = $this->getJson('/api/instructors?postcode=SW1A&transmission=manual');

        $response->assertOk()
            ->assertJsonFragment(['id' => $target->id])
            ->assertJsonMissing(['id' => $otherPostcode->id])
            ->assertJsonMissing(['id' => $otherTransmission->id]);
    }

    public function test_public_user_can_view_approved_instructor_details(): void
    {
        $instructor = InstructorProfileFactory::new()->create([
            'hourly_rate' => 45.00,
            'transmission' => 'manual',
            'approved' => true,
        ]);

        $response = $this->getJson("/api/instructors/{$instructor->id}");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $instructor->id,
                'hourly_rate' => '45.00',
                'transmission' => 'manual',
            ])
            ->assertJsonStructure([
                'id',
                'user_id',
                'postcode',
                'hourly_rate',
                'transmission',
                'user' => ['id', 'name', 'email'],
            ]);
    }

    public function test_public_user_cannot_view_unapproved_instructor_details(): void
    {
        $unapprovedInstructor = InstructorProfileFactory::new()->create([
            'approved' => false,
        ]);

        $response = $this->getJson("/api/instructors/{$unapprovedInstructor->id}");

        $response->assertNotFound();
    }

    public function test_viewing_nonexistent_instructor_returns_404(): void
    {
        $response = $this->getJson('/api/instructors/99999');

        $response->assertNotFound();
    }

    public function test_authenticated_instructor_can_create_profile(): void
    {
        $user = User::factory()->instructor()->create();

        $payload = [
            'phone' => '07123456789',
            'bio' => 'Experienced DVSA grade A driving instructor.',
            'postcode' => 'sw1a 1aa',
            'city' => 'London',
            'transmission' => 'manual',
            'hourly_rate' => 42.50,
        ];

        $response = $this->actingAs($user)->postJson('/api/instructors', $payload);

        $response->assertCreated()
            ->assertJsonFragment([
                'user_id' => $user->id,
                'postcode' => 'SW1A 1AA', // normalized to uppercase
                'transmission' => 'manual',
                'hourly_rate' => '42.50',
            ])
            ->assertJsonFragment([
                'message' => 'Instructor profile created and awaiting approval.',
            ]);

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $user->id,
            'postcode' => 'SW1A 1AA',
            'transmission' => 'manual',
            'approved' => 0,
        ]);
    }

    public function test_instructor_cannot_self_approve_during_profile_creation(): void
    {
        $user = User::factory()->instructor()->create();

        $payload = [
            'phone' => '07123456789',
            'bio' => 'Experienced driving instructor.',
            'postcode' => 'SW1A 1AA',
            'transmission' => 'manual',
            'hourly_rate' => 45.00,
            'approved' => true,
        ];

        $response = $this->actingAs($user)->postJson('/api/instructors', $payload);

        $response->assertCreated();

        $profile = InstructorProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertFalse((bool) $profile->approved);

        // Verify it does not appear in public listing
        $publicList = $this->getJson('/api/instructors');
        $publicList->assertOk()->assertJsonMissing(['id' => $profile->id]);

        // Verify it returns 404 in public details
        $publicDetail = $this->getJson("/api/instructors/{$profile->id}");
        $publicDetail->assertNotFound();
    }

    public function test_instructor_cannot_create_duplicate_profile(): void
    {
        $user = User::factory()->instructor()->create();
        InstructorProfileFactory::new()->create(['user_id' => $user->id]);

        $payload = [
            'phone' => '07123456789',
            'postcode' => 'SW1A 1AA',
            'transmission' => 'manual',
            'hourly_rate' => 45.00,
        ];

        $response = $this->actingAs($user)->postJson('/api/instructors', $payload);

        $response->assertStatus(409)
            ->assertJsonFragment(['message' => 'Instructor profile already exists.']);
    }

    public function test_student_cannot_create_instructor_profile(): void
    {
        $student = User::factory()->student()->create();

        $payload = [
            'phone' => '07123456789',
            'postcode' => 'SW1A 1AA',
            'transmission' => 'manual',
            'hourly_rate' => 40.00,
        ];

        $response = $this->actingAs($student)->postJson('/api/instructors', $payload);

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_create_instructor_profile(): void
    {
        $payload = [
            'postcode' => 'SW1A 1AA',
            'transmission' => 'manual',
            'hourly_rate' => 40.00,
        ];

        $response = $this->postJson('/api/instructors', $payload);

        $response->assertUnauthorized();
    }

    public function test_instructor_can_update_their_own_profile_hourly_rate_and_transmission(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create([
            'user_id' => $user->id,
            'hourly_rate' => 35.00,
            'transmission' => 'manual',
            'bio' => 'Original bio',
        ]);

        $response = $this->actingAs($user)->putJson("/api/instructors/{$profile->id}", [
            'hourly_rate' => 48.00,
            'transmission' => 'automatic',
            'bio' => 'Updated bio with 10 years experience',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'hourly_rate' => '48.00',
                'transmission' => 'automatic',
                'bio' => 'Updated bio with 10 years experience',
            ])
            ->assertJsonFragment([
                'message' => 'Instructor profile updated.',
            ]);

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $profile->id,
            'hourly_rate' => 48.00,
            'transmission' => 'automatic',
            'bio' => 'Updated bio with 10 years experience',
        ]);
    }

    public function test_instructor_cannot_self_approve_during_profile_update(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->unapproved()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->putJson("/api/instructors/{$profile->id}", [
            'hourly_rate' => 50.00,
            'approved' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $profile->id,
            'approved' => false,
        ]);

        // Still does not appear in public listing
        $publicList = $this->getJson('/api/instructors');
        $publicList->assertOk()->assertJsonMissing(['id' => $profile->id]);

        // Still returns 404 in public details
        $publicDetail = $this->getJson("/api/instructors/{$profile->id}");
        $publicDetail->assertNotFound();
    }

    public function test_instructor_cannot_update_another_instructors_profile(): void
    {
        $userA = User::factory()->instructor()->create();
        $userB = User::factory()->instructor()->create();
        $profileB = InstructorProfileFactory::new()->create(['user_id' => $userB->id]);

        $response = $this->actingAs($userA)->putJson("/api/instructors/{$profileB->id}", [
            'hourly_rate' => 99.00,
        ]);

        $response->assertForbidden();
    }

    public function test_student_cannot_update_instructor_profile(): void
    {
        $student = User::factory()->student()->create();
        $profile = InstructorProfileFactory::new()->create();

        $response = $this->actingAs($student)->putJson("/api/instructors/{$profile->id}", [
            'hourly_rate' => 99.00,
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_update_any_instructor_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = InstructorProfileFactory::new()->create([
            'hourly_rate' => 30.00,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/instructors/{$profile->id}", [
            'hourly_rate' => 50.00,
            'city' => 'Birmingham',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'hourly_rate' => '50.00',
                'city' => 'Birmingham',
            ]);

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $profile->id,
            'hourly_rate' => 50.00,
            'city' => 'Birmingham',
        ]);
    }

    public function test_instructor_can_delete_their_own_profile(): void
    {
        $user = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson("/api/instructors/{$profile->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Instructor profile deleted.']);

        $this->assertDatabaseMissing('instructor_profiles', [
            'id' => $profile->id,
        ]);
    }

    public function test_instructor_cannot_delete_another_instructors_profile(): void
    {
        $userA = User::factory()->instructor()->create();
        $userB = User::factory()->instructor()->create();
        $profileB = InstructorProfileFactory::new()->create(['user_id' => $userB->id]);

        $response = $this->actingAs($userA)->deleteJson("/api/instructors/{$profileB->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('instructor_profiles', ['id' => $profileB->id]);
    }

    public function test_student_cannot_delete_instructor_profile(): void
    {
        $student = User::factory()->student()->create();
        $profile = InstructorProfileFactory::new()->create();

        $response = $this->actingAs($student)->deleteJson("/api/instructors/{$profile->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('instructor_profiles', ['id' => $profile->id]);
    }

    public function test_admin_can_delete_any_instructor_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = InstructorProfileFactory::new()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/instructors/{$profile->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Instructor profile deleted.']);

        $this->assertDatabaseMissing('instructor_profiles', ['id' => $profile->id]);
    }
}
