<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of architecture-v2 moved User to App\Modules\Auth\Models\User
 * (and other models into their modules). Polymorphic columns in existing
 * tables stored the old App\Models\* FQCNs, so this rewrites them to the
 * stable aliases declared in AppServiceProvider::enforceMorphMap.
 *
 * Idempotent: running twice on already-rewritten data is a no-op.
 */
return new class extends Migration
{
    private array $rewrites = [
        'App\\Models\\User' => 'user',
        'App\\Models\\Chat' => 'chat',
        'App\\Models\\ChatMessage' => 'chat_message',
        'App\\Models\\Anamnesis' => 'anamnesis',
    ];

    public function up(): void
    {
        $this->rewrite('personal_access_tokens', 'tokenable_type');
        $this->rewrite('model_has_roles', 'model_type');
        $this->rewrite('model_has_permissions', 'model_type');
    }

    public function down(): void
    {
        // No-op: enforcing the morph map is the desired end state.
        // Reverting would re-orphan tokens after the next deploy.
    }

    private function rewrite(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        foreach ($this->rewrites as $old => $new) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }
    }
};
