<?php

namespace Homeport\Laravel\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class Job implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}
