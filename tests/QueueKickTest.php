<?php

namespace Homeport\Laravel\Tests;

use Homeport\Laravel\HomeportServiceProvider;
use Homeport\Laravel\QueueKicker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QueueKickTest extends TestCase
{
    private const URL = 'https://api.homeport.sh/v1/queue/kick/abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    protected function getPackageProviders($app): array
    {
        return [HomeportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('homeport.queue.kick_url', self::URL);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Http::fake(fn () => Http::response('', $this->answer));
    }

    /** What the fake homeport answers a kick. */
    private int $answer = 202;

    private function noKickUrl(): void
    {
        config(['homeport.queue.kick_url' => null]);
        putenv('HOMEPORT_QUEUE_KICK_URL');
    }

    #[Test]
    public function a_burst_of_jobs_sends_one_kick_after_the_request(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Job::dispatch();
        }

        $this->assertSame(5, \DB::table('jobs')->count());
        Http::assertNothingSent(); // not while the request is running

        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::URL && $r->body() === '');
    }

    #[Test]
    public function the_next_request_kicks_again(): void
    {
        Job::dispatch();
        $this->app->terminate();
        $this->app->make(QueueKicker::class)->queued();
        $this->app->make(QueueKicker::class)->flush();

        Http::assertSentCount(2);
    }

    #[Test]
    public function nothing_is_sent_off_homeport(): void
    {
        $this->noKickUrl();

        Job::dispatch();
        $this->app->terminate();

        $this->assertSame(1, \DB::table('jobs')->count());
        $this->assertFalse($this->app->make(QueueKicker::class)->pending());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_url_is_read_from_the_environment_when_config_lacks_it(): void
    {
        config(['homeport.queue.kick_url' => null]); // config cached without it
        putenv('HOMEPORT_QUEUE_KICK_URL='.self::URL);

        try {
            Job::dispatch();
            $this->app->terminate();
        } finally {
            putenv('HOMEPORT_QUEUE_KICK_URL');
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function jobs_run_in_process_are_not_kicked(): void
    {
        config(['queue.default' => 'sync']);
        config(['queue.connections.sync' => ['driver' => 'sync']]);

        Job::dispatch();
        $this->app->make(QueueKicker::class)->queued('sync');
        $this->app->terminate();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_failed_kick_never_fails_the_request_and_is_logged_without_the_token(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for '.self::URL));
        Log::spy();

        Job::dispatch();
        $this->app->terminate();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $m) => str_contains($m, 'timed out') && ! str_contains($m, 'abcdefghij')
        );
    }

    #[Test]
    public function an_error_answer_is_logged(): void
    {
        $this->answer = 404;
        Log::spy();

        Job::dispatch();
        $this->app->terminate();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, '404'));
    }

    #[Test]
    public function a_long_lived_process_sends_its_kick_once_the_window_passes(): void
    {
        config(['homeport.queue.kick_window' => 0]);

        Job::dispatch();
        Http::assertNothingSent();
        Job::dispatch(); // the window (0 s) has passed: sent now

        Http::assertSentCount(1);
        $this->app->terminate(); // nothing left pending
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_provider_is_auto_discovered(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        $this->assertSame([HomeportServiceProvider::class], $composer['extra']['laravel']['providers']);
    }
}
