<?php

namespace Tests\Feature;

use App\Models\InstructorProfile;
use App\Models\User;
use Database\Factories\InstructorProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_an_instructor(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = InstructorProfileFactory::new()->unapproved()->create();

        $response = $this->actingAs($admin)->patchJson("/api/admin/instructors/{$instructor->id}/approval", [
            'approved' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('instructor.approved', true)
            ->assertJsonPath('message', 'Instructor approved successfully.');

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $instructor->id,
            'approved' => true,
        ]);
    }

    public function test_admin_can_revoke_instructor_approval(): void
    {
        $admin = User::factory()->admin()->create();
        $instructor = InstructorProfileFactory::new()->create(['approved' => true]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/instructors/{$instructor->id}/approval", [
            'approved' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('instructor.approved', false)
            ->assertJsonPath('message', 'Instructor approval revoked.');

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $instructor->id,
            'approved' => false,
        ]);
    }

    public function test_instructor_cannot_change_approval(): void
    {
        $instructorUser = User::factory()->instructor()->create();
        $profile = InstructorProfileFactory::new()->unapproved()->create();

        $response = $this->actingAs($instructorUser)->patchJson("/api/admin/instructors/{$profile->id}/approval", [
            'approved' => true,
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $profile->id,
            'approved' => false,
        ]);
    }

    public function test_student_cannot_change_approval(): void
    {
        $student = User::factory()->student()->create();
        $profile = InstructorProfileFactory::new()->unapproved()->create();

        $response = $this->actingAs($student)->patchJson("/api/admin/instructors/{$profile->id}/approval", [
            'approved' => true,
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('instructor_profiles', [
            'id' => $profile->id,
            'approved' => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_change_approval(): void
    {
        $profile = InstructorProfileFactory::new()->unapproved()->create();

        $response = $this->patchJson("/api/admin/instructors/{$profile->id}/approval", [
            'approved' => true,
        ]);

        $response->assertUnauthorized();
    }

    public function test_approval_requires_boolean_value(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = InstructorProfileFactory::new()->unapproved()->create();

        $response = $this->actingAs($admin)->patchJson("/api/admin/instructors/{$profile->id}/approval", [
            'approved' => 'not-a-boolean',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['approved']);
    }

    public function test_admin_can_view_pending_instructors(): void
    {
        $admin = User::factory()->admin()->create();
        $unapproved = InstructorProfileFactory::new()->unapproved()->create();
        $approved = InstructorProfileFactory::new()->create(['approved' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/instructors/pending');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unapproved->id, $ids);
        $this->assertNotContains($approved->id, $ids);
    }

    public function test_non_admin_cannot_view_pending_instructors(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->getJson('/api/admin/instructors/pending');

        $response->assertForbidden();
    }
}
