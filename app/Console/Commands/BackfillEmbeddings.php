<?php

namespace App\Console\Commands;

use App\Modules\AI\Jobs\EmbedMessageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches EmbedMessageJob for every chat_messages row whose embedding
 * column is NULL. Used once after the pgvector migration to backfill
 * historical messages, and re-runnable safely (the job itself is idempotent).
 *
 * Postgres-only — exits early on sqlite where the column doesn't exist.
 */
class BackfillEmbeddings extends Command
{
    protected $signature = 'ai:backfill-embeddings
                            {--chunk=100 : Number of rows to scan per batch}';

    protected $description = 'Queue an embedding job for every chat_messages row missing an embedding';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('ai:backfill-embeddings only runs on Postgres (the embedding column is pgvector-specific).');

            return self::SUCCESS;
        }

        $chunk = (int) $this->option('chunk');
        $dispatched = 0;

        DB::table('chat_messages')
            ->whereNull('embedding')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$dispatched) {
                foreach ($rows as $row) {
                    EmbedMessageJob::dispatch($row->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} embedding jobs.");

        return self::SUCCESS;
    }
}
