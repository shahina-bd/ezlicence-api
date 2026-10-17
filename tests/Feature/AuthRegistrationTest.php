<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_as_student(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Student User',
            'email' => 'student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'student');

        $this->assertDatabaseHas('users', [
            'email' => 'student@example.com',
            'role' => 'student',
        ]);
    }

    public function test_user_can_register_as_instructor(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Instructor User',
            'email' => 'instructor@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'instructor',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'instructor');

        $this->assertDatabaseHas('users', [
            'email' => 'instructor@example.com',
            'role' => 'instructor',
        ]);
    }

    public function test_user_cannot_register_as_admin(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('users', [
            'email' => 'admin@example.com',
        ]);
    }

    public function test_user_cannot_register_with_invalid_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Super User',
            'email' => 'superuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'superadmin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_user_can_login_with_valid_credentials_and_access_me_and_logout(): void
    {
        $user = User::factory()->student()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertOk()
            ->assertJsonStructure(['message', 'user', 'token'])
            ->assertJsonPath('user.id', $user->id);

        $token = $loginResponse->json('token');

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me');

        $meResponse->assertOk()
            ->assertJsonPath('user.email', 'login@example.com');

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('message', 'Logout successful');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->student()->create([
            'email' => 'valid@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'valid@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid email or password');
    }
}
