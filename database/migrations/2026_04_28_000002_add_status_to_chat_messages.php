<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Streaming chat completion can fail mid-flight — we used to either
 * save the partial text as if it were a complete reply or discard it.
 * Adding an explicit status lets the client distinguish.
 *
 * Default 'complete' so existing rows aren't disturbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('status', 16)->default('complete')->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
