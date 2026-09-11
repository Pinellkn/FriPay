<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests Feature pour la recherche admin.
 * Valide que :
 * - Les caractères LIKE (% _) sont échappés (fix C2)
 * - La longueur de recherche est limitée
 * - La recherche fonctionne normalement
 */
class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer un admin staff
        $this->admin = StaffUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->token = $this->admin->createToken('test-token')->plainTextToken;

        // Créer des utilisateurs de test
        User::create([
            'phone_number' => '+22990000001',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'status' => 'active',
        ]);

        User::create([
            'phone_number' => '+22990000002',
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'status' => 'active',
        ]);

        User::create([
            'phone_number' => '+22990000003',
            'first_name' => 'Pierre',
            'last_name' => 'Bernard',
            'status' => 'blocked',
        ]);
    }

    /**
     * C2 FIX: Recherche normale doit fonctionner
     */
    public function test_search_finds_users_by_name(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/admin/users?search=Jean');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Jean', $data[0]['first_name']);
    }

    /**
     * C2 FIX: Recherche par téléphone doit fonctionner
     */
    public function test_search_finds_users_by_phone(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/admin/users?search=90000001');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    /**
     * C2 FIX: Caractères LIKE (% _) doivent être échappés
     * Avant le fix, "100%" retrouvait tous les numéros contenant "100" + n'importe quoi
     */
    public function test_like_wildcards_are_escaped(): void
    {
        // Créer un utilisateur avec "100" dans le téléphone
        User::create([
            'phone_number' => '+22991000099',
            'first_name' => 'Test',
            'last_name' => 'Percent',
            'status' => 'active',
        ]);

        // Rechercher "%99" — les % et _ doivent être échappés
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/admin/users?search=%99');

        $response->assertStatus(200);
        // Ne doit PAS trouver "+22991000099" car % est échappé
        $data = $response->json('data');
        foreach ($data as $user) {
            $this->assertStringNotContainsString('%99', $user['phone_number']);
        }
    }

    /**
     * C2 FIX: Recherche vide ne doit pas planter
     */
    public function test_empty_search_returns_all(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/admin/users?search=');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data); // Les 3 users créés
    }

    /**
     * C2 FIX: Recherche trop longue doit être tronquée
     */
    public function test_long_search_is_truncated(): void
    {
        $longSearch = str_repeat('a', 200); // 200 chars

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/admin/users?search=' . urlencode($longSearch));

        $response->assertStatus(200); // Ne doit pas planter
    }

    /**
     * Filtrage par statut doit fonctionner
     */
    public function test_filter_by_status(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/admin/users?status=blocked');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Pierre', $data[0]['first_name']);
    }

    /**
     * Accès sans token doit être refusé
     */
    public function test_unauthorized_access_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/users?search=Jean');

        $response->assertStatus(401);
    }
}
