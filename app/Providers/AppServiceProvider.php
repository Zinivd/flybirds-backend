<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
