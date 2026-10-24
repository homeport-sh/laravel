<?php

namespace Homeport\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Env;
use Throwable;

/**
 * Tells homeport that work was queued: one POST to HOMEPORT_QUEUE_KICK_URL,
 * sent when the request (or command) terminates, after the response has
 * gone out. A burst of jobs in one request sends one kick. It never throws:
 * a failed kick is logged and the job waits for the next activity drain.
 */
final class QueueKicker
{
    /** Drivers that run the job in this process: nothing to drain. */
    private const IN_PROCESS = ['sync', 'null', 'deferred', 'background'];

    private ?float $pendingSince = null;

    public function __construct(private Application $app) {}

    /** The kick URL, or null off homeport. */
    public function url(): ?string
    {
        $url = $this->app['config']->get('homeport.queue.kick_url')
            ?: Env::get('HOMEPORT_QUEUE_KICK_URL'); // config cached without it

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** A job was queued on the connection: kick once this request ends. */
    public function queued(?string $connection = null): void
    {
        if ($this->url() === null || $this->inProcess($connection)) {
            return;
        }

        if ($this->pendingSince !== null) {
            $window = (float) $this->app['config']->get('homeport.queue.kick_window', 10);
            if (microtime(true) - $this->pendingSince >= $window) {
                $this->flush(); // a long-lived process: don't sit on it
            }

            return;
        }

        $this->pendingSince = microtime(true);
        $this->app->terminating(fn () => $this->flush());
    }

    /** Whether a kick is waiting to be sent. */
    public function pending(): bool
    {
        return $this->pendingSince !== null;
    }

    /** Sends the pending kick, if any. Never throws. */
    public function flush(): void
    {
        if ($this->pendingSince === null) {
            return;
        }
        $this->pendingSince = null;

        $url = $this->url();
        if ($url === null) {
            return;
        }

        try {
            $timeout = (float) $this->app['config']->get('homeport.queue.kick_timeout', 2);
            $response = $this->app->make(Http::class)
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->send('POST', $url);

            if (! $response->successful()) {
                $this->warn('homeport answered '.$response->status());
            }
        } catch (Throwable $e) {
            // the URL holds the environment's token: keep it out of logs
            $this->warn(str_replace($url, '[HOMEPORT_QUEUE_KICK_URL]', $e->getMessage()));
        }
    }

    private function inProcess(?string $connection): bool
    {
        $config = $this->app['config'];
        $connection ??= $config->get('queue.default');
        $driver = $config->get("queue.connections.{$connection}.driver");

        return in_array($driver, self::IN_PROCESS, true);
    }

    private function warn(string $why): void
    {
        try {
            $this->app['log']->warning("homeport: the queue kick wasn't sent ({$why}); the job runs at the next drain");
        } catch (Throwable) {
            // logging must not fail the request either
        }
    }
}
