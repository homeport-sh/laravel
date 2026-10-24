<?php

namespace Homeport\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\ServiceProvider;

final class HomeportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/homeport.php', 'homeport');

        // scoped: one per request under Octane, one per process otherwise
        $this->app->scoped(QueueKicker::class, fn ($app) => new QueueKicker($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/homeport.php' => $this->app->configPath('homeport.php'),
            ], 'homeport-config');
        }

        $this->app->make(Dispatcher::class)->listen(JobQueued::class, KickOnJobQueued::class);
    }
}
