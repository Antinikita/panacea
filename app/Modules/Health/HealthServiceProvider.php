<?php

namespace App\Modules\Health;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/routes.php');
    }
}
