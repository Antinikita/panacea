<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen health snapshot stored alongside each anamnesis at generation
 * time. Lets clinical records reference the user's exact metrics + norm
 * comparison at the moment the AI summarized the conversation, instead
 * of re-fetching live data which would have changed since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anamneses', function (Blueprint $table) {
            $table->json('health_context')->nullable()->after('ai_raw_response');
        });
    }

    public function down(): void
    {
        Schema::table('anamneses', function (Blueprint $table) {
            $table->dropColumn('health_context');
        });
    }
};
