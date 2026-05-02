<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Services\Embedder;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes and stores a vector embedding for a single chat message.
 *
 * Idempotent: skips if the row already has an embedding. Postgres-only
 * (the embedding column doesn't exist on sqlite); on other drivers
 * the job is a no-op.
 *
 * Dispatched by the EmbedNewMessage listener for both user and
 * assistant messages, and by the ai:backfill-embeddings command for
 * historical rows.
 */
class EmbedMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $messageId) {}

    public function handle(Embedder $embedder): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $message = ChatMessage::find($this->messageId);
        if (!$message || trim($message->message) === '') {
            return;
        }

        // Idempotent: already embedded.
        $existing = DB::selectOne(
            'SELECT embedding IS NOT NULL AS has_embedding FROM chat_messages WHERE id = ?',
            [$message->id]
        );
        if ($existing && $existing->has_embedding) {
            return;
        }

        try {
            $vector = $embedder->embed($message->message);
        } catch (\Throwable $e) {
            Log::error('EmbedMessageJob: embedder failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        DB::update(
            'UPDATE chat_messages SET embedding = ? WHERE id = ?',
            ['['.implode(',', $vector).']', $message->id],
        );
    }
}
