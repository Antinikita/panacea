<?php

namespace App\Modules\Chat\Events;

use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantReplyCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public ChatMessage $message) {}
}
