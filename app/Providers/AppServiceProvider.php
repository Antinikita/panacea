<?php

namespace App\Providers;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'chat' => Chat::class,
            'chat_message' => ChatMessage::class,
            'anamnesis' => Anamnesis::class,
        ]);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
