<?php

namespace App\Modules\Anamnesis;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AnamnesisServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/routes.php');
    }
}
