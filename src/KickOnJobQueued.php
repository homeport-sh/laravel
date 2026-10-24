<?php

namespace Homeport\Laravel;

use Illuminate\Queue\Events\JobQueued;

final class KickOnJobQueued
{
    public function __construct(private QueueKicker $kicker) {}

    public function handle(JobQueued $event): void
    {
        $this->kicker->queued($event->connectionName);
    }
}
