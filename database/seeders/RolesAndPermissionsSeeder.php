<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
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

        // Can't search by `email` directly — it's encrypted with a random
        // IV per row, so the same plaintext yields different ciphertexts
        // and WHERE never matches. Use the byEmail() helper which routes
        // through the email_hash sidecar column.
        $adminEmail = 'admin@panacea.local';
        $adminUser = User::byEmail($adminEmail);
        if (! $adminUser) {
            $adminUser = new User();
            $adminUser->name = 'Admin';
            $adminUser->email = $adminEmail;
            $adminUser->password = 'adminpass'; // 'hashed' cast applies on save
            $adminUser->sex = 'male';
            $adminUser->age = 35;
            $adminUser->save(); // saving event populates email_hash
        }
        $adminUser->syncRoles(['admin']);
    }
}
