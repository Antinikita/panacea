<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // No WithoutModelEvents here: we rely on the User model's `saving`
    // event (from User::booted()) to compute email_hash from the
    // encrypted email. Suppressing events would insert with NULL
    // email_hash and trip the NOT NULL constraint.
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
