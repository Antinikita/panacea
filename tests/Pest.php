<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Module-grouped tests live in app/Modules/<Name>/Tests/Feature and bind
// Tests\TestCase + RefreshDatabase via uses() at the top of each file,
// because Pest only auto-applies the extension to tests under tests/.

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function seedPermissions(): void
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
}
