<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt existing PII rows so the new model casts don't trip on
 * legacy plaintext values. Idempotent in both directions: each cell
 * is probed with Crypt::decryptString — if it decrypts, it's already
 * encrypted (skip on up, unwrap on down); if not, treat as plaintext.
 *
 * Chat messages stay plaintext on purpose: the table has a Postgres
 * generated tsvector column and a pgvector embedding both derived
 * from `message`, and encrypting it would break full-text search and
 * RAG retrieval.
 */
return new class extends Migration
{
    private array $userCols = ['name', 'sex'];

    private array $anamnesisTextCols = [
        'chief_complaint',
        'history_present_illness',
        'past_medical_history',
        'family_history',
        'social_history',
        'allergies',
        'medications',
        'review_of_systems',
    ];

    private array $anamnesisJsonCols = ['health_context', 'ai_raw_response'];

    public function up(): void
    {
        // Encrypted ciphertext is an opaque base64 blob, not valid JSON.
        // Postgres rejects it on jsonb columns, so convert to text first.
        DB::statement('ALTER TABLE anamneses ALTER COLUMN ai_raw_response TYPE text USING ai_raw_response::text');
        DB::statement('ALTER TABLE anamneses ALTER COLUMN health_context TYPE text USING health_context::text');

        DB::table('users')->orderBy('id')->each(function ($row) {
            $updates = [];
            foreach ($this->userCols as $col) {
                $val = $row->{$col} ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                if (!$this->isEncrypted($val)) {
                    $updates[$col] = Crypt::encryptString((string) $val);
                }
            }
            if ($updates) {
                DB::table('users')->where('id', $row->id)->update($updates);
            }
        });

        DB::table('anamneses')->orderBy('id')->each(function ($row) {
            $updates = [];
            foreach ($this->anamnesisTextCols as $col) {
                $val = $row->{$col} ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                if (!$this->isEncrypted($val)) {
                    $updates[$col] = Crypt::encryptString((string) $val);
                }
            }
            foreach ($this->anamnesisJsonCols as $col) {
                $val = $row->{$col} ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                if (!$this->isEncrypted($val)) {
                    // Existing rows hold raw JSON text. Encrypt the JSON
                    // string itself; the model's 'encrypted:array' cast
                    // will decrypt-then-decode on read.
                    $updates[$col] = Crypt::encryptString((string) $val);
                }
            }
            if ($updates) {
                DB::table('anamneses')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->orderBy('id')->each(function ($row) {
            $updates = [];
            foreach ($this->userCols as $col) {
                $val = $row->{$col} ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $plain = $this->tryDecrypt($val);
                if ($plain !== null) {
                    $updates[$col] = $plain;
                }
            }
            if ($updates) {
                DB::table('users')->where('id', $row->id)->update($updates);
            }
        });

        DB::table('anamneses')->orderBy('id')->each(function ($row) {
            $updates = [];
            foreach (array_merge($this->anamnesisTextCols, $this->anamnesisJsonCols) as $col) {
                $val = $row->{$col} ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $plain = $this->tryDecrypt($val);
                if ($plain !== null) {
                    $updates[$col] = $plain;
                }
            }
            if ($updates) {
                DB::table('anamneses')->where('id', $row->id)->update($updates);
            }
        });

        DB::statement('ALTER TABLE anamneses ALTER COLUMN ai_raw_response TYPE jsonb USING ai_raw_response::jsonb');
        DB::statement('ALTER TABLE anamneses ALTER COLUMN health_context TYPE jsonb USING health_context::jsonb');
    }

    private function isEncrypted(string $val): bool
    {
        return $this->tryDecrypt($val) !== null;
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
