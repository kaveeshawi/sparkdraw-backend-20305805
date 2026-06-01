<?php

namespace App\Providers;

use App\Services\AIService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single shared instance — avoids reconstructing HTTP client per request
        $this->app->singleton(AIService::class, fn () => new AIService());
    }

    public function boot(): void {}
}
