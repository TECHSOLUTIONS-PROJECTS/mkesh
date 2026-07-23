<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\MkeshClient;

/**
 * Registers the MKESH client into the Laravel container and publishes config.
 */
final class MkeshServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'mkesh');

        $this->app->singleton(MkeshConfig::class, static function (Application $app): MkeshConfig {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('mkesh', []);

            return MkeshConfig::fromArray($config);
        });

        $this->app->singleton(MkeshClient::class, static function (Application $app): MkeshClient {
            return MkeshClient::create($app->make(MkeshConfig::class));
        });

        $this->app->alias(MkeshClient::class, 'mkesh');
    }

    public function boot(): void
    {
        // Run the package migrations as-is. Publish them instead (see below) if
        // you need to change the schema.
        $this->loadMigrationsFrom($this->migrationsPath());

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('mkesh.php'),
            ], 'mkesh-config');

            $this->publishes([
                $this->migrationsPath() => $this->app->databasePath('migrations'),
            ], 'mkesh-migrations');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [MkeshConfig::class, MkeshClient::class, 'mkesh'];
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/mkesh.php';
    }

    private function migrationsPath(): string
    {
        return __DIR__ . '/../../database/migrations';
    }
}
