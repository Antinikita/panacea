<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'email_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email_hash', 64)->nullable()->after('email');
            });
        }

        $appKey = $this->resolveAppKey();

        DB::table('users')->orderBy('id')->each(function ($row) use ($appKey) {
            $email = $row->email;
            if (!$email) {
                return;
            }

            $hash = $this->hashEmail($email, $appKey);
            $emailValue = $row->email;

            if (!$this->looksEncrypted($emailValue)) {
                $emailValue = Crypt::encryptString($emailValue);
            }

            DB::table('users')->where('id', $row->id)->update([
                'email_hash' => $hash,
                'email' => $emailValue,
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email_hash SET NOT NULL');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_hash_unique ON users (email_hash)');
        }
    }

    public function down(): void
    {
        $appKey = $this->resolveAppKey();

        DB::table('users')->orderBy('id')->each(function ($row) use ($appKey) {
            $emailValue = $row->email;
            if (!$emailValue) {
                return;
            }
            try {
                $plain = Crypt::decryptString($emailValue);
            } catch (\Throwable) {
                return;
            }
            DB::table('users')->where('id', $row->id)->update(['email' => $plain]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_hash_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email)');
        }

        if (Schema::hasColumn('users', 'email_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_hash');
            });
        }
    }

    private function hashEmail(string $email, string $appKey): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $appKey);
    }

    private function looksEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveAppKey(): string
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return $key;
    }
};
