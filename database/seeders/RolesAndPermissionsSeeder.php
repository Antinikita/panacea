<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'chat.create', 'chat.read', 'chat.update', 'chat.delete',
            'anamnesis.create', 'anamnesis.read', 'anamnesis.update', 'anamnesis.delete',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        $user = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $user->syncPermissions($permissions);

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@panacea.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('adminpass'),
                'sex' => 'male',
                'age' => 35,
            ]
        );
        $adminUser->syncRoles(['admin']);
    }
}
