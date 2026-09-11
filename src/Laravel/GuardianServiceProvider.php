<?php

declare(strict_types=1);

namespace Guardian\Laravel;

use Guardian\Application\Console\ArtisanBaselineCommand;
use Guardian\Application\Console\ArtisanDoctorCommand;
use Guardian\Application\Console\ArtisanExplainCommand;
use Guardian\Application\Console\ArtisanListCommand;
use Guardian\Application\Console\ArtisanScanCommand;
use Guardian\Application\Guardian;
use Illuminate\Support\ServiceProvider;

final class GuardianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/guardian.php', 'guardian');

        $this->app->singleton(Guardian::class, static fn (): Guardian => Guardian::createDefault());
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 2).'/config/guardian.php' => config_path('guardian.php'),
        ], 'guardian-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ArtisanScanCommand::class,
                ArtisanBaselineCommand::class,
                ArtisanListCommand::class,
                ArtisanExplainCommand::class,
                ArtisanDoctorCommand::class,
            ]);
        }
    }
}
