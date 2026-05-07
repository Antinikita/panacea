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

        DB::statement('DROP INDEX IF EXISTS chat_messages_metadata_gin');
        DB::statement('ALTER TABLE chat_messages ALTER COLUMN metadata TYPE text USING metadata::text');
        DB::statement('ALTER TABLE chat_messages ALTER COLUMN metadata DROP DEFAULT');
        DB::statement('ALTER TABLE chat_messages ALTER COLUMN metadata DROP NOT NULL');

        DB::table('chat_messages')->orderBy('id')->each(function ($row) {
            $val = $row->metadata;
            if ($val === null || $val === '') {
                return;
            }
            try {
                Crypt::decryptString($val);
                return;
            } catch (\Throwable) {
            }
            DB::table('chat_messages')
                ->where('id', $row->id)
                ->update(['metadata' => Crypt::encryptString((string) $val)]);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('chat_messages')->orderBy('id')->each(function ($row) {
            $val = $row->metadata;
            if ($val === null || $val === '') {
                return;
            }
            try {
                $plain = Crypt::decryptString($val);
                DB::table('chat_messages')->where('id', $row->id)->update(['metadata' => $plain]);
            } catch (\Throwable) {
            }
        });

        DB::statement("ALTER TABLE chat_messages ALTER COLUMN metadata TYPE jsonb USING metadata::jsonb");
        DB::statement("ALTER TABLE chat_messages ALTER COLUMN metadata SET DEFAULT '{}'");
        DB::statement('CREATE INDEX IF NOT EXISTS chat_messages_metadata_gin ON chat_messages USING GIN (metadata jsonb_path_ops)');
    }
};
