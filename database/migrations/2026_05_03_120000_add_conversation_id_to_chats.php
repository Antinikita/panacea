<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the ai-service's conversation_id (UUIDv4) on each chat so the
 * AI side can keep its own Redis-backed history + summary instead of us
 * resending the full transcript every turn.
 *
 * Lazy: created on first AI response; existing chats get one on their
 * next message. Indexed because debug queries occasionally look up by
 * the AI-side id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('conversation_id', 36)->nullable()->after('title');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
            $table->dropColumn('conversation_id');
        });
    }
};
