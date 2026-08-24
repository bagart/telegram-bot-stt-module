<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class TelegramBotSttServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stt.php', 'stt');

        // Composer-installed module discovery (config/telegram.php contract)
        $providers = (array) Config::get('telegram.modules_providers', []);
        Config::set('telegram.modules_providers', array_values(array_unique(array_merge(
            $providers,
            [SttModule::class],
        ))));

        $this->commands([
            Console\SttPruneCommand::class,
            Console\SttDoctorCommand::class,
        ]);

        // Singleton wiring: container-managed contracts only (DI rule, §5).
        $this->app->singleton(SttSettingsService::class);

        // Provider + HTTP factory are container-managed so tests can swap them.
        if (! $this->app->bound(Factory::class)) {
            $this->app->singleton(Factory::class);
        }

        $this->app->singleton(Provider\SttProviderContract::class, Provider\Adapter\OpenAiCompatibleStt::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
