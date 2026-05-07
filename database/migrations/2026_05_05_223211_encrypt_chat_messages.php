<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt chat_messages.message at rest.
 *
 * The message column previously had a Postgres GENERATED tsvector column
 * `tsv` derived from it. Generated columns block ALTER on the source
 * column and would index encrypted gibberish anyway, so we drop tsv
 * (along with its GIN index) — full-text search wasn't wired into any
 * query path. Vector/embedding-based RAG is preserved: the embedder
 * reads $message->message through the Eloquent model, which decrypts
 * via the 'encrypted' cast before computing the vector.
 *
 * Idempotent: each row is probed with Crypt::decryptString. If it
 * already decrypts, it's already encrypted — skip on up, unwrap on down.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS chat_messages_tsv_gin');
        DB::statement('ALTER TABLE chat_messages DROP COLUMN IF EXISTS tsv');

        DB::table('chat_messages')->orderBy('id')->each(function ($row) {
            $val = $row->message ?? null;
            if ($val === null || $val === '') {
                return;
            }
            if ($this->tryDecrypt($val) !== null) {
                return; // already encrypted
            }
            DB::table('chat_messages')
                ->where('id', $row->id)
                ->update(['message' => Crypt::encryptString((string) $val)]);
        });
    }

    public function down(): void
    {
        DB::table('chat_messages')->orderBy('id')->each(function ($row) {
            $val = $row->message ?? null;
            if ($val === null || $val === '') {
                return;
            }
            $plain = $this->tryDecrypt($val);
            if ($plain !== null) {
                DB::table('chat_messages')
                    ->where('id', $row->id)
                    ->update(['message' => $plain]);
            }
        });

        DB::statement(
            "ALTER TABLE chat_messages ADD COLUMN tsv tsvector "
            ."GENERATED ALWAYS AS (to_tsvector('simple', coalesce(message,''))) STORED"
        );
        DB::statement('CREATE INDEX chat_messages_tsv_gin ON chat_messages USING GIN (tsv)');
    }

    private function tryDecrypt(string $val): ?string
    {
        try {
            return Crypt::decryptString($val);
        } catch (\Throwable) {
            return null;
        }
    }
};
