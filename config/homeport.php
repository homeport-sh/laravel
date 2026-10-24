<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue kick
    |--------------------------------------------------------------------------
    |
    | On homeport, queues are drained on demand: the control plane runs
    | `queue:work --stop-when-empty` when there may be work. When a job is
    | queued, this package POSTs once to HOMEPORT_QUEUE_KICK_URL (set by
    | homeport for each environment) after the response is sent, so the
    | drain starts in seconds instead of at the next pace.
    |
    | No URL (local development, anywhere off homeport): nothing is sent.
    |
    */

    'queue' => [

        'kick_url' => env('HOMEPORT_QUEUE_KICK_URL'),

        // Seconds to wait for homeport before giving up. A lost kick only
        // means the job waits for the next activity drain.
        'kick_timeout' => (float) env('HOMEPORT_QUEUE_KICK_TIMEOUT', 2),

        // A process that never terminates (a daemon dispatching jobs) sends
        // its pending kick at the next queued job once this many seconds
        // have passed. homeport's floor between kick drains is 10 seconds.
        'kick_window' => 10,

    ],

];
