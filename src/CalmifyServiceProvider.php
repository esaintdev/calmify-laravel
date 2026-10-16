<?php

namespace Calmify\Laravel;

use Calmify\Laravel\Console\InstallCommand;
use Calmify\Laravel\Console\PairCommand;
use Illuminate\Support\ServiceProvider;

class CalmifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/calmify.php', 'calmify');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/calmify.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/calmify.php' => config_path('calmify.php')], 'calmify-config');
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, PairCommand::class]);
        }
    }
}
