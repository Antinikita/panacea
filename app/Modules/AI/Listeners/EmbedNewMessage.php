<?php

namespace App\Modules\AI\Listeners;

use App\Modules\AI\Jobs\EmbedMessageJob;
use App\Modules\Chat\Events\AssistantReplyCreated;
use App\Modules\Chat\Events\MessageSent;

class EmbedNewMessage
{
    public function handle(MessageSent|AssistantReplyCreated $event): void
    {
        EmbedMessageJob::dispatch($event->message->id)->afterCommit();
    }
}
