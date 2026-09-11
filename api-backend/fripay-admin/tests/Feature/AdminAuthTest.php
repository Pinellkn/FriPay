<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests Feature pour AdminAuthController (fripay-admin).
 *
 * Couvre :
 * - POST /api/v1/admin/auth/login (connexion back-office)
 * - Verification des credentials, du role et des permissions
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── POST /api/v1/admin/auth/login ─────────────────────────────

    public function test_login_returns_token_on_valid_credentials(): void
    {
        $role = \App\Models\Role::create(['name' => 'super_admin', 'description' => 'Admin complet']);
        $permission = \App\Models\Permission::create(['code' => 'users.read', 'description' => 'Lire les users']);
        $role->permissions()->attach($permission);

        StaffUser::create([
            'email' => 'admin@test.com',
            'password_hash' => Hash::make('password123'),
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'access_token',
            'role',
            'permissions',
        ]);
        $response->assertJson([
            'role' => 'super_admin',
        ]);
        $this->assertContains('users.read', $response->json('permissions'));
    }

    public function test_login_rejects_wrong_password(): void
    {
        $role = \App\Models\Role::create(['name' => 'operator']);
        StaffUser::create([
            'email' => 'admin2@test.com',
            'password_hash' => Hash::make('password123'),
            'first_name' => 'Admin',
            'last_name' => 'Two',
            'role_id' => $role->id,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin2@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'type' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_login_rejects_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_rejects_inactive_account(): void
    {
        $role = \App\Models\Role::create(['name' => 'operator']);
        StaffUser::create([
            'email' => 'inactive@test.com',
            'password_hash' => Hash::make('password123'),
            'first_name' => 'Inactive',
            'last_name' => 'Staff',
            'role_id' => $role->id,
            'active' => false,
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'inactive@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/login', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_invalid_email_format(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }
}
