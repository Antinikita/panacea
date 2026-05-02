<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Postgres++. Adds full-text + semantic search columns to
 * chat_messages, plus a metadata JSONB column for flexible per-message
 * payload (RAG sources, intent, etc).
 *
 * Postgres-only by design: HNSW + tsvector + JSONB don't have sqlite
 * equivalents, and pgvector is a server-side extension. On non-pgsql
 * drivers the migration is a no-op so the rest of the test suite keeps
 * running on sqlite.
 *
 * The chat_messages.metadata column already exists as a `json` column
 * from Phase 1; this migration drops and re-adds it as `jsonb` only on
 * Postgres so we get GIN indexability.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Re-create metadata as jsonb for GIN indexability.
        // The column was created as json by Phase 1's migration; jsonb is
        // a strict superset of json's API but stores parsed binary form.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });

        DB::statement("ALTER TABLE chat_messages ADD COLUMN metadata jsonb NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE chat_messages ADD COLUMN embedding vector(1536)');
        DB::statement(
            "ALTER TABLE chat_messages ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(message,''))) STORED"
        );

        DB::statement('CREATE INDEX chat_messages_tsv_gin ON chat_messages USING GIN (tsv)');
        DB::statement('CREATE INDEX chat_messages_metadata_gin ON chat_messages USING GIN (metadata jsonb_path_ops)');
        DB::statement('CREATE INDEX chat_messages_embedding_hnsw ON chat_messages USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX chat_messages_chat_created ON chat_messages (chat_id, created_at)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS chat_messages_chat_created');
        DB::statement('DROP INDEX IF EXISTS chat_messages_embedding_hnsw');
        DB::statement('DROP INDEX IF EXISTS chat_messages_metadata_gin');
        DB::statement('DROP INDEX IF EXISTS chat_messages_tsv_gin');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'tsv', 'metadata']);
        });

        DB::statement("ALTER TABLE chat_messages ADD COLUMN metadata json NULL");
    }
};
