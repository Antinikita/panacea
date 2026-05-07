<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE health_metrics ALTER COLUMN value TYPE text USING value::text');

        DB::table('health_metrics')->orderBy('id')->each(function ($row) {
            $val = $row->value;
            if ($val === null || $val === '' || $this->looksEncrypted($val)) {
                return;
            }
            DB::table('health_metrics')
                ->where('id', $row->id)
                ->update(['value' => Crypt::encryptString((string) $val)]);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('health_metrics')->orderBy('id')->each(function ($row) {
            $val = $row->value;
            if ($val === null || $val === '') {
                return;
            }
            try {
                $plain = Crypt::decryptString($val);
                DB::table('health_metrics')->where('id', $row->id)->update(['value' => $plain]);
            } catch (\Throwable) {
                // already plaintext
            }
        });

        DB::statement('ALTER TABLE health_metrics ALTER COLUMN value TYPE double precision USING value::double precision');
    }

    private function looksEncrypted(string $val): bool
    {
        try {
            Crypt::decryptString($val);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
