<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE text USING email::text');
        DB::statement('ALTER TABLE users ALTER COLUMN name TYPE text USING name::text');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE varchar(255)');
        DB::statement('ALTER TABLE users ALTER COLUMN name TYPE varchar(255)');
    }
};
