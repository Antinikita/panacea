<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chats', 'complaint_id')) {
            Schema::table('chats', function (Blueprint $table) {
                try {
                    $table->dropForeign(['complaint_id']);
                } catch (\Throwable $e) {
                    // FK may not exist on a fresh DB where complaints was never created; ignore
                }
                $table->dropColumn('complaint_id');
            });
        }

        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('complaints');
    }

    public function down(): void
    {
        // One-way cut: legacy tables are not restored.
    }
};
