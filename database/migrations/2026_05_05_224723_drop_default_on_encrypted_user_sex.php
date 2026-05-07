<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the database-level default ('male') on users.sex.
 *
 * Since users.sex is now encrypted via Eloquent's 'encrypted' cast, any
 * INSERT that omits the column would have Postgres inject the literal
 * string 'male' bypassing the cast — the row ends up with plaintext on
 * disk, and the next read crashes inside Encrypter::decrypt('male').
 * The application layer already supplies a 'male' fallback in
 * AuthController::register, so the DB default is both redundant and
 * actively harmful.
 *
 * users.age is plaintext integer; its default(30) is fine and stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the plaintext default and let the column be NULL when no
        // sex is supplied. Inserts that bypass the model would otherwise
        // either keep injecting plaintext or fail a NOT NULL constraint.
        // DROP DEFAULT and DROP NOT NULL are both idempotent in Postgres
        // (they no-op if already absent), so this migration is safe to
        // re-run after a partial application.
        DB::statement('ALTER TABLE users ALTER COLUMN sex DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN sex DROP NOT NULL');
    }

    public function down(): void
    {
        // Backfill any nulls before re-imposing NOT NULL, then restore.
        DB::statement("UPDATE users SET sex = 'male' WHERE sex IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN sex SET NOT NULL');
        DB::statement("ALTER TABLE users ALTER COLUMN sex SET DEFAULT 'male'");
    }
};
