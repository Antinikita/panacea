<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

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
