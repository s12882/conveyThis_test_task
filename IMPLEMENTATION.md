# Implementation Plan — Async File Uploader with TTL & RabbitMQ Notifications

Source of truth: `TASK.md`. Architecture decisions below are locked in; this expands the earlier draft into a concrete milestone/task breakdown.

## 1. Current state

- Laravel 13 skeleton (fresh `laravel/laravel` install), no auth scaffolding, Tailwind wired in `package.json` (to be swapped for Bootstrap + jQuery), no Docker files, no RabbitMQ package yet.

## 2. Decisions

| # | Question | Decision |
|---|---|---|
| 1 | Auth | None — single shared file list, no login |
| 2 | RabbitMQ style | Raw AMQP publish/consume via `php-amqplib`, not a Laravel queue driver |
| 3 | "Sending" the email | Real `Mail`/`Mailable` sent through the `log` mail driver (rendered email lands in `storage/logs/laravel.log`) |
| 4 | TTL enforcement | Delayed job dispatched at upload time, fires ~24h later and deletes that file |
| 5 | "Asynchronous" upload | AJAX request (no full page reload); storage happens synchronously within that request |
| 6 | File list scope | List + manual delete only (no download/preview) |
| 7 | Upload cardinality | Single file per upload |
| 8 | Tests | Covered by automated PHPUnit tests, per milestone |
| 9 | Docker topology | Separate containers for the queue worker and the scheduler |

## 3. Finalized architecture

Two independent async mechanisms, both funneling into one shared deletion path:

- **Laravel's own queue** (`QUEUE_CONNECTION=database`, `jobs` table) is used *only* for the per-file delayed TTL-deletion job (decision 4). Consumed by a dedicated **queue-worker** container running `php artisan queue:work`.
- **RabbitMQ** (raw AMQP via `php-amqplib`, decision 2) is used *only* for the deletion → email-notification event. A deletion (manual or TTL) publishes a message; a dedicated **rabbitmq-consumer** container runs a long-lived Artisan command that consumes it and sends the logged email.
- A **scheduler** container runs `php artisan schedule:work` as a safety net — periodically reaps any file whose `expires_at` has passed but wasn't cleaned up by its delayed job (e.g. the job was lost across a deploy/restart). This reuses the same deletion path, so it also triggers the RabbitMQ notification.

Both the manual-delete controller action and the delayed job (and the safety-net reaper) call one shared `FileDeletionService`, so "delete the file + publish the RabbitMQ event" logic isn't duplicated.

```
Upload (AJAX) -> store file + DB row -> dispatch delayed job (fires in 24h)
                                                |
Manual delete (UI) ----------------------------+--> FileDeletionService
Scheduler safety net (reaper, periodic) -------+        |
Delayed TTL job (fires ~24h later) ------------+        |
                                                         v
                                          delete physical file + mark row deleted
                                                         |
                                                         v
                                      publish AMQP message -> RabbitMQ queue
                                                         |
                                                         v
                                   rabbitmq-consumer command -> Mailable (log driver)
```

### Docker Compose services

1. `app` — PHP-FPM + nginx, serves the web app
2. `mysql`
3. `rabbitmq` (management plugin enabled, for visibility into queues)
4. `queue-worker` — same app image, `php artisan queue:work database`
5. `rabbitmq-consumer` — same app image, custom Artisan command consuming the deletion queue
6. `scheduler` — same app image, `php artisan schedule:work`
7. Frontend assets (Bootstrap/jQuery + Vite) built at image build time, not a running service

## 4. Data model

`files` table:

- `id`
- `original_name` (string)
- `stored_path` (string)
- `mime_type` (string)
- `size_bytes` (unsigned int)
- `expires_at` (timestamp, `created_at` + 24h)
- `deletion_reason` (nullable enum/string: `manual`, `ttl_expired`, `ttl_safety_net`)
- `deleted_at` (soft delete)
- `created_at`, `updated_at`

Laravel's `jobs` (+ `failed_jobs`) tables via `php artisan queue:table`, used solely for the delayed TTL job.

New `.env` var: `FILE_DELETION_NOTIFICATION_EMAIL` (the recipient address named in the task).

## 5. Milestones

**M0 — Environment & Docker scaffolding**
- [x] `docker-compose.yml`: app, webserver (nginx), mysql, rabbitmq, queue-worker, scheduler (`rabbitmq-consumer` deferred to M5, once its command exists)
- [x] Dockerfile for the app image (multi-stage: Node build for assets + PHP 8.3-fpm, composer install) — `docker/php/Dockerfile`, nginx vhost at `docker/nginx/default.conf`
- [x] `QUEUE_CONNECTION=database` (already Laravel 13 default), `jobs`/`cache` tables migrated
- [x] Swap Tailwind → Bootstrap + jQuery in `package.json`/Vite entry points
- [x] Add `FILE_DELETION_NOTIFICATION_EMAIL` (plus `RABBITMQ_*`, `FILE_TTL_HOURS`) to `.env`/`.env.example`

Verified working (2026-09-15): app reachable via nginx at `http://localhost:8000` (Laravel welcome page, 200), MySQL migrated and reachable, RabbitMQ management UI at `http://localhost:15672` (200), `queue-worker` and `scheduler` containers running cleanly, Bootstrap + jQuery confirmed loaded and functional (navbar/card markup, jQuery-driven button, Bootstrap CSS variables present in the built bundle).

**M0 is now complete.** `vendor/` and `public/build/` are plain bind-mounted host directories (not named volumes — see `SESSION_LOG.md` for why); after any `composer.json`/`package.json`/frontend change, re-run `composer install` / `npm run build` (host-side or via a throwaway container) before relying on the running containers.

**M1 — Upload backend**
- [ ] `files` migration + `File` model (with `SoftDeletes`)
- [ ] Upload endpoint: validate mime type (PDF/DOCX) + 10MB size limit, store via filesystem disk, persist metadata, set `expires_at`
- [ ] Dispatch delayed `DeleteExpiredFile` job at `expires_at`
- [ ] Feature tests: valid upload, rejected mime type, rejected oversized file, job scheduled

**M2 — Upload frontend**
- [ ] Upload page (Bootstrap layout), jQuery-driven AJAX submit with progress/feedback
- [ ] Client-side type/size checks mirroring server-side validation

**M3 — File management page**
- [ ] List page (name, size, uploaded-at, expires-at) via Bootstrap table
- [ ] Manual delete action (AJAX), wired through `FileDeletionService`
- [ ] Feature tests: list reflects DB state, delete removes file + row + triggers deletion event

**M4 — Shared deletion path + TTL**
- [ ] `FileDeletionService`: delete physical file, soft-delete row with `deletion_reason`, publish AMQP message
- [ ] `DeleteExpiredFile` job (consumes the database queue) calling the service with `ttl_expired`
- [ ] `files:reap-expired` Artisan command + schedule entry (safety net), reason `ttl_safety_net`
- [ ] Tests: job execution deletes file; reaper catches an "orphaned" expired row

**M5 — RabbitMQ notification**
- [ ] `php-amqplib` integration: publisher (inside `FileDeletionService`) + queue/exchange declaration
- [ ] `rabbitmq:consume-file-deletions` Artisan command: consumes messages, sends `FileDeletedNotification` Mailable to `FILE_DELETION_NOTIFICATION_EMAIL`
- [ ] `MAIL_MAILER=log` config
- [ ] Tests: publisher enqueues expected payload (mock channel); consumer command sends mail for a given message (`Mail::fake()`)

**M6 — Polish**
- [ ] End-to-end manual verification via `docker compose up`
- [ ] README run instructions (services, ports, how to watch logs for the "sent" email)
- [ ] Cleanup, error handling for edge cases (upload during low disk space, RabbitMQ temporarily down, etc.)

## 6. Remaining open items for later milestones (not blocking start)

- Exact base Docker images / whether app + nginx are one container or two (default: two, `php-fpm` + `nginx`, unless you'd rather simplify).
- Whether the RabbitMQ management UI port should be exposed for convenience during development (default: yes, on 15672).
