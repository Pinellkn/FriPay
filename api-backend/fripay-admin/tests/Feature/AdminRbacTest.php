<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests Feature pour le RBAC admin (fripay-admin).
 *
 * Valide :
 * - Les routes protegees refusent l'acces sans token
 * - Le middleware admin:xxx verifie les permissions
 * - Un staff sans la permission recoit 403
 * - Un staff avec la permission accede a la route
 * - Un staff desactive recoit 403
 */
class AdminRbacTest extends TestCase
{
    use RefreshDatabase;

    private Role $operatorRole;
    private Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Creer la table users (appartient a fripay-users, pas a fripay-admin)
        if (!DB::getSchemaBuilder()->hasTable('users')) {
            DB::statement('CREATE TABLE users (
                id TEXT PRIMARY KEY,
                phone_number TEXT NOT NULL UNIQUE,
                first_name TEXT,
                last_name TEXT,
                status TEXT DEFAULT "active",
                kyc_status TEXT DEFAULT "pending",
                client_type TEXT DEFAULT "P",
                preferred_language TEXT DEFAULT "fr",
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        // Creer les permissions
        $perms = [];
        foreach (['users.read', 'users.block', 'transactions.read', 'transactions.retry',
                  'corridors.read', 'corridors.write', 'dashboard.read', 'staff.read', 'staff.write'] as $code) {
            $perms[$code] = Permission::create(['code' => $code, 'description' => $code]);
        }

        // Role "operator" : permissions limitees
        $this->operatorRole = Role::create(['name' => 'operator', 'description' => 'Operateur']);
        $this->operatorRole->permissions()->attach([
            $perms['users.read']->id,
            $perms['transactions.read']->id,
            $perms['dashboard.read']->id,
        ]);

        // Role "super_admin" : toutes les permissions
        $this->superAdminRole = Role::create(['name' => 'super_admin', 'description' => 'Admin complet']);
        $this->superAdminRole->permissions()->attach($perms);
    }

    private function createStaff(Role $role, bool $active = true): StaffUser
    {
        return StaffUser::create([
            'email' => $role->name . '@test.com',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Staff',
            'last_name' => $role->name,
            'role_id' => $role->id,
            'active' => $active,
        ]);
    }

    private function createToken(StaffUser $staff): string
    {
        return $staff->createToken('test-token', ['*'])->plainTextToken;
    }

    // ─── Authentication ────────────────────────────────────────────

    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/v1/admin/users');

        $response->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-value',
        ])->getJson('/api/v1/admin/users');

        $response->assertStatus(401);
    }

    // ─── RBAC: corridors.write ────────────────────────────────────

    public function test_operator_cannot_create_corridor(): void
    {
        $staff = $this->createStaff($this->operatorRole);
        $token = $this->createToken($staff);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/corridors', [
            'source_operator' => 'MTN_MOMO',
            'destination_operator' => 'MOOV_MONEY',
            'rail' => 'aggregator',
            'priority' => 1,
            'fee_type' => 'fixed',
            'fee_value' => 100,
            'min_amount' => 100,
            'max_amount' => 500000,
        ]);

        $response->assertStatus(403);
    }

    // ─── RBAC: staff.write ────────────────────────────────────────

    public function test_operator_cannot_create_staff(): void
    {
        $staff = $this->createStaff($this->operatorRole);
        $token = $this->createToken($staff);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/staff', [
            'email' => 'new@test.com',
            'password' => 'password123',
            'first_name' => 'New',
            'last_name' => 'Staff',
            'role_id' => $this->operatorRole->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_create_staff(): void
    {
        $staff = $this->createStaff($this->superAdminRole);
        $token = $this->createToken($staff);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/admin/staff', [
            'email' => 'new-staff@test.com',
            'password' => 'password123',
            'first_name' => 'New',
            'last_name' => 'Staff',
            'role_id' => $this->operatorRole->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('staff_users', [
            'email' => 'new-staff@test.com',
            'active' => true,
        ]);
    }

    // ─── Disabled staff ────────────────────────────────────────────

    public function test_disabled_staff_returns_403(): void
    {
        $staff = $this->createStaff($this->superAdminRole, false);
        $token = $this->createToken($staff);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/admin/staff');

        $response->assertStatus(403);
        $response->assertJson([
            'type' => 'ACCOUNT_DISABLED',
        ]);
    }

    // ─── Corridors read ────────────────────────────────────────────

    public function test_super_admin_can_read_corridors(): void
    {
        $staff = $this->createStaff($this->superAdminRole);
        $token = $this->createToken($staff);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/admin/corridors');

        $response->assertOk();
    }

    public function test_operator_cannot_write_corridors(): void
    {
        $staff = $this->createStaff($this->operatorRole);
        $token = $this->createToken($staff);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->putJson('/api/v1/admin/corridors/1', [
            'fee_value' => 200,
        ]);

        $response->assertStatus(403);
    }
}
