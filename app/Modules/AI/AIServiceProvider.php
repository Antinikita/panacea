<?php

namespace App\Modules\AI;

use App\Modules\AI\Listeners\EmbedNewMessage;
use App\Modules\Chat\Events\AssistantReplyCreated;
use App\Modules\Chat\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(MessageSent::class, [EmbedNewMessage::class, 'handle']);
        Event::listen(AssistantReplyCreated::class, [EmbedNewMessage::class, 'handle']);
    }
}
