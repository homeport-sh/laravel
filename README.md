# homeport/laravel

Runs your queued Laravel jobs within seconds on [homeport](https://homeport.sh).

On homeport, queues are drained on demand: nothing runs `queue:work` all the time. The control plane starts a drain
(`queue:work --stop-when-empty`) when there may be work, such as after your app served requests. That needs no code,
but a job can wait up to the plan's pace (5 minutes on paid plans, 15 on Free) before it runs.

This package removes the wait. When a job is queued, it tells homeport once, after the response has been sent, and on
paid plans the drain starts at once.

## Install

```bash
composer require homeport/laravel
```

That's all. The service provider is auto-discovered and there's nothing to configure: homeport sets
`HOMEPORT_QUEUE_KICK_URL` for each environment with a queue drained on demand, and your app picks it up on its next
deploy.

## What it does

- Listens for Laravel's `JobQueued` event (any connection that isn't run in the process, like `sync`).
- Sends one `POST` to `HOMEPORT_QUEUE_KICK_URL` when the request or command ends, after the response has gone out. A
  request that queues 50 jobs sends one kick.
- Never slows down or fails the request that queued the job: a 2-second timeout, and an error is only logged as a
  warning (without the URL, which holds your environment's token). A lost kick only means the job runs at the next
  drain.
- In a process that never ends (a daemon that dispatches jobs), a pending kick is sent with the next queued job once 10
  seconds have passed.

homeport handles kicks sensibly: a kick during a running drain is folded into it, kick-started drains are at least
10 seconds apart, and on Free kicks keep to the 15-minute pace.

## Off homeport

Without `HOMEPORT_QUEUE_KICK_URL` (local development, CI, any other host) the package does nothing: no requests, no
logs. Your queue works exactly as before.

## Configuration (optional)

You don't need any. To change the timeout, publish the config:

```bash
php artisan vendor:publish --tag=homeport-config
```

| Key | Env | Default |
| --- | --- | --- |
| `homeport.queue.kick_url` | `HOMEPORT_QUEUE_KICK_URL` | set by homeport |
| `homeport.queue.kick_timeout` | `HOMEPORT_QUEUE_KICK_TIMEOUT` | `2` seconds |
| `homeport.queue.kick_window` | | `10` seconds |

If your config is cached without the variable, the package reads it from the environment at runtime.

To kick from something that isn't this Laravel app (another service writing to the jobs table, say), `POST` to the
same URL; it takes no body and answers `202`.

## Requirements

PHP 8.2+ and Laravel 11, 12 or 13.

## License

MIT, see [LICENSE](LICENSE).
