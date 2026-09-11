<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions
        $permissions = [
            ['code' => 'users.read', 'description' => 'Consulter les utilisateurs'],
            ['code' => 'users.block', 'description' => 'Bloquer/débloquer un utilisateur'],
            ['code' => 'transactions.read', 'description' => 'Consulter les transactions'],
            ['code' => 'transactions.retry', 'description' => 'Relancer une transaction en échec'],
            ['code' => 'corridors.read', 'description' => 'Consulter les corridors'],
            ['code' => 'corridors.write', 'description' => 'Créer/modifier les corridors'],
            ['code' => 'dashboard.read', 'description' => 'Consulter le tableau de bord'],
            ['code' => 'staff.read', 'description' => 'Consulter le personnel'],
            ['code' => 'staff.write', 'description' => 'Gérer le personnel'],
        ];

        foreach ($permissions as $perm) {
            Permission::create($perm);
        }

        // Rôle Super Admin
        $superAdmin = Role::create(['name' => 'super_admin', 'description' => 'Accès complet à toutes les fonctionnalités']);
        $superAdmin->permissions()->attach(Permission::all()->pluck('id'));

        // Rôle Support
        $supportRole = Role::create(['name' => 'support', 'description' => 'Support client - consultation et actions limitées']);
        $supportRole->permissions()->attach(
            Permission::whereIn('code', ['users.read', 'transactions.read', 'transactions.retry', 'dashboard.read'])->pluck('id')
        );

        // Rôle Finance
        $financeRole = Role::create(['name' => 'finance', 'description' => 'Gestion des corridors et rapports financiers']);
        $financeRole->permissions()->attach(
            Permission::whereIn('code', ['transactions.read', 'corridors.read', 'corridors.write', 'dashboard.read'])->pluck('id')
        );

        // Utilisateur admin par défaut
        StaffUser::create([
            'email' => 'admin@fripay.bj',
            'password_hash' => Hash::make('admin1234'),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'role_id' => $superAdmin->id,
            'active' => true,
        ]);
    }
}
