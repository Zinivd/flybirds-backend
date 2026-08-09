<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\DelhiveryService;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
   public function register(): void
{
    $this->app->singleton(DelhiveryService::class, fn () => new DelhiveryService());
}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
{
    // Allow clean migrate:refresh without FK constraint errors
    if (app()->runningInConsole()) {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    }
}
}
