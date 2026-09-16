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

A third independent concern feeds the same shared deletion path: **virus/macro scanning**. Every upload is queued (immediately, no delay) for a scan job on the same database queue as the TTL job. `clean` just updates the row; `infected` calls `FileDeletionService` with `deletion_reason = 'infected'` — so an infected upload is deleted and triggers the exact same RabbitMQ notification as any other deletion, no new notification plumbing needed. See `SESSION_LOG.md` (2026-09-15, "Upload edge-case analysis") and `~/.claude/plans/before-starting-implementation-i-nested-lerdorf.md` for the full analysis and reasoning (why async scanning is safe to use here specifically: decision 6 already rules out any download/preview feature, so there's no window where a not-yet-scanned file is exposed).

### Docker Compose services

1. `app` — PHP-FPM + nginx, serves the web app
2. `mysql`
3. `rabbitmq` (management plugin enabled, for visibility into queues)
4. `queue-worker` — same app image, `php artisan queue:work database`
5. `rabbitmq-consumer` — same app image, custom Artisan command consuming the deletion queue
6. `scheduler` — same app image, `php artisan schedule:work`
7. `clamav` — official `clamav/clamav` image, running `clamd`; persistent volume for the signature DB
8. Frontend assets (Bootstrap/jQuery + Vite) built at image build time, not a running service

## 4. Data model

`files` table:

- `id`
- `original_name` (string)
- `stored_path` (string)
- `mime_type` (string)
- `size_bytes` (unsigned int)
- `expires_at` (timestamp, `created_at` + 24h)
- `deletion_reason` (nullable enum/string: `manual`, `ttl_expired`, `ttl_safety_net`, `infected`)
- `scan_status` (enum/string: `pending`, `clean`, `infected`, `error` — default `pending`)
- `scanned_at` (nullable timestamp)
- `deleted_at` (soft delete)
- `created_at`, `updated_at`

Laravel's `jobs` (+ `failed_jobs`) tables via `php artisan queue:table`, used for the delayed TTL job *and* the immediate (no-delay) virus-scan job.

New `.env` vars: `FILE_DELETION_NOTIFICATION_EMAIL` (the recipient address named in the task), `CLAMAV_HOST`, `CLAMAV_PORT`.

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
- [x] `files` migration (incl. `scan_status`, `scanned_at`) — `database/migrations/2026_09_15_210808_create_files_table.php`
- [x] `File` model (with `SoftDeletes`) — `app/Models/File.php`, factory at `database/factories/FileFactory.php` (with `expired()`/`clean()`/`infected()` states for upcoming tests)
- [x] Upload endpoint: validate mime type (PDF/DOCX) + 10MB size limit, store via filesystem disk, persist metadata, set `expires_at` — `POST /files`, `FileUploadController@store`, `app/Http/Requests/StoreFileRequest.php`
- [x] Filename encoding check: `mb_check_encoding($originalName, 'UTF-8')`, reject 422 if invalid — `app/Rules/ValidFilenameEncoding.php`
- [x] Structural sanity check: PDF magic bytes (`%PDF-`) / DOCX ZIP-openable with `[Content_Types].xml`, reject 422 if malformed — `app/Rules/ValidDocumentIntegrity.php`
- [x] Dispatch delayed `DeleteExpiredFile` job at `expires_at` — dispatched from `FileUploadController::store()`
- [x] Dispatch immediate `ScanUploadedFile` job (virus/macro scan via ClamAV — see below) — dispatched from `store()`, hand-built by the user in parallel (`App\Jobs\ScanUploadedFile`, `App\Services\VirusScanService`); scan logic itself still has a `// TODO`
- [x] Feature tests: valid upload (PDF + DOCX), rejected mime type, rejected oversized file, rejected bad-encoding filename, rejected malformed/spoofed file, `DeleteExpiredFile` dispatch+delay assertion — `tests/Feature/FileUploadTest.php`, 9 tests passing

**Note (2026-09-16):** from here on, the user is hand-editing files in parallel with me — `App\Services\FileDeletionService` (manual delete, `FileUploadController::destroy()`) and `App\Jobs\ScanUploadedFile`/`App\Services\VirusScanService` are theirs to finish; I re-read files before editing and flag anything that looks like an unintended bug rather than silently fixing or reverting. See `SESSION_LOG.md` for the reconciliation details.

**M1.5 — Virus/macro scanning (ClamAV)**
- [x] `docker-php-ext-install sockets` in `docker/php/Dockerfile`; rebuild `app`/`queue-worker`/`scheduler`
- [x] `clamav` service in `docker-compose.yml` (`clamav/clamav:1.5`, persistent signature-DB volume, healthcheck); `queue-worker` depends on it
- [x] `CLAMAV_HOST`/`CLAMAV_PORT` in `.env`/`.env.example`
- [x] Verified end-to-end: `ext-sockets` loaded, `PING`→`PONG`, EICAR test string correctly detected via `zINSTREAM`, clean payload passes (2026-09-16)
- [x] Composer: `xenolope/quahog` (the actual clamd-socket client `sunspikes/clamav-validator` wraps — installed directly, skipping the unused Laravel-validation-rule layer). `config/services.php` gained a `clamav` block (`host`/`port`/`timeout`). `app/Services/VirusScanService.php` wraps it (`scanStream(string $contents): Xenolope\Quahog\Result`).
- [x] `app/Jobs/ScanUploadedFile.php` completed: reads the file from the `local` disk, scans via `VirusScanService`, `isFound()` → `FileDeletionService::delete($id, 'infected', 'local')`, `isOk()` → `scan_status='clean'`, otherwise `scan_status='error'`; `failed()` sets `scan_status='error'` once retries are exhausted (`tries=3`, `backoff=5`).
- [x] `docker-compose.yml`: `queue-worker` now runs `queue:work database --queue=scans,default` (was missing the `scans` queue entirely — jobs dispatched via `->onQueue('scans')` were never being picked up until this was fixed).
- [x] Tests: `tests/Feature/ScanUploadedFileTest.php` (6 tests, mocked scanner/deletion service — clean/infected/error/missing-record/missing-file/failed-permanently paths) and `tests/Feature/VirusScanServiceIntegrationTest.php` (2 tests against the **real** clamd container using the EICAR string). Also manually verified against the live stack end-to-end (real MySQL + real queue-worker + real clamd): a clean upload → `scan_status=clean`; an EICAR upload → physically deleted from disk + `deletion_reason=infected`.

**Bugs found in `FileDeletionService` (2026-09-16, first pass — flagged for the user, who owns this file):** `delete()` sets `deletion_reason` and does physically delete the file from disk, but never calls `$file->delete()` — the row is never soft-deleted (`deleted_at` stays null, `trashed()` is false), confirmed via the live end-to-end check above. Also its AMQP-publish call sits after an early `return` inside the "file exists on disk" branch, making it unreachable in the normal case. Both matter for M1.5/M4/M5 to actually work end-to-end.

**Re-checked 2026-09-16 after the user's fix:** soft-delete now works (`deleteWithReason()` added to `File`, sets `deletion_reason` then calls `$this->delete()`) and the AMQP-publish call is now reachable — both confirmed live (`trashed()=true`, `deleted_at` set, file gone from disk). **Still broken:** `AMPQService::$connection` is a typed property that's never initialized anywhere before use (no constructor, nothing assigns it) — every deletion now throws `Typed property App\Services\AMPQService::$connection must not be accessed before initialization`, caught by `delete()`'s try/catch, logged, and `delete()` returns `false` even though the file cleanup itself already succeeded by that point. No RabbitMQ notification can fire until this is fixed. (Full test suite re-run: still 17/17 passing — this doesn't break anything visible in tests since none of them exercise the real `AMPQService`.)

**Deferred to M5 (2026-09-16):** user commented out the `AMPQService` call in `FileDeletionService::delete()` (`// TODO AMPQService`) rather than fixing it now — matches the milestone plan (RabbitMQ wiring is M5, not M1.5). Re-verified with it commented: `delete()` now returns `true` cleanly and the live end-to-end check shows `trashed()=true`, file removed from disk, no error logged. `ScanUploadedFileTest` re-run (targeted, not the full suite) — 6/6 still passing.
- [x] `ScanUploadedFile` job: streams file to `clamd`; infected → `FileDeletionService::delete($file, reason: 'infected')`; clean → `scan_status='clean'`, `scanned_at=now()`; exhausted retries on scanner error → `scan_status='error'` (never silently default to clean)
- [x] Tests: mocked-scanner unit tests for clean/infected paths; optional EICAR-string integration test against real `clamd`

**M2 — Upload frontend** & **M3 — File management page** (expanded plan, 2026-09-16: `~/.claude/plans/before-starting-implementation-i-nested-lerdorf.md`)

Decisions: `FileController` (new) owns `index()`+`destroy()` (moved from `FileUploadController`, which stays upload-only with `create()`+`store()`); delete confirmation via a Bootstrap modal, not native `confirm()`; file list paginated (15/page).

View architecture — a Laravel layout+partials system plus shared Blade components, so pages share consistent chrome and reusable pieces aren't copy-pasted:
- `resources/views/layouts/app.blade.php` — master layout (`@yield('content')`), includes `partials/navbar.blade.php` + `partials/alerts.blade.php`, adds the CSRF `<meta>` tag.
- `resources/views/components/button.blade.php` and `components/badge.blade.php` — shared, prop-driven (`<x-button variant="" size="">`, `<x-badge variant="">`), used across both pages.
- `resources/views/files/upload.blade.php` (M2) and `files/index.blade.php` (M3), plus `files/partials/delete-modal.blade.php`.
- `resources/views/welcome.blade.php` retired.

- [ ] Routes: `GET /` → `FileUploadController@create`; `GET /files` → `FileController@index`; `DELETE /files/{file}` moved to `FileController@destroy`; `POST /files` unchanged (**note:** the user has already hand-built `FileController` with `index()`+`destroy()` and wired `GET /files`/`DELETE /files/{file}` to it in `routes/web.php`, matching this decision — only `GET /` still needs to move from the inline closure to `FileUploadController@create`)
- [x] Layout + partials + `<x-button>`/`<x-badge>` components — `resources/views/layouts/app.blade.php`, `partials/navbar.blade.php`, `partials/alerts.blade.php`, `components/button.blade.php`, `components/badge.blade.php`. Navbar uses plain `url()` links (not named routes) since `GET /`/`GET /files` aren't named yet — will switch to `route()` once M2/M3 name them.
- [x] M2 upload page: file input + client-side type/size pre-checks, jQuery AJAX submit (`FormData`) with progress bar, success/error alert handling — `resources/views/files/upload.blade.php`, `FileUploadController::create()`, `GET /` named `upload.create`
- [ ] M3 file list page: paginated Bootstrap table (name/size/uploaded-at/expires-at/`scan_status` badge), delete via Bootstrap-modal-confirmed AJAX call
- [ ] M3 sorting (added 2026-09-16): sortable by `created_at`/`expires_at`/`size_bytes` via whitelisted `sort`/`direction` query params (default `created_at`/`desc`, matching current behavior); clickable column headers (first click = ascending, click again = toggle), direction indicator, sort preserved across pagination (`$files->appends(request()->query())`)
- [x] `resources/js/app.js`: global CSRF `ajaxSetup`, upload-form handler (client-side extension/size pre-check driven by `data-*` attributes from `config('files.max_size_kb')`, progress bar, AJAX alert injection) — delete-modal handler still pending (M3)
- [x] Tests: `GET /` smoke test — `tests/Feature/UploadPageTest.php`. `FileControllerTest` for `index()`/`destroy()` still pending (M3)

**M2 done (2026-09-16).** Along the way, reviewed `StoreFileRequest` at the user's request and fixed two small issues: a dead `file.mimes` message key (rule is `mimetypes`, not `mimes` — that message could never fire) and the hardcoded `10240`/`"10 MB"` duplication (now both derive from a new `config('files.max_size_kb')`, backed by `FILE_MAX_SIZE_KB` in `.env`). Verified end-to-end via a real AJAX-shaped HTTP request (proper CSRF token + `X-Requested-With`/`Accept` headers, not just PHPUnit) — 201, file stored, DB record created. Full Feature suite: 17/17 passing.

**M4 — Shared deletion path + TTL**
- [x] `FileDeletionService`: delete physical file, soft-delete row with `deletion_reason` (`manual`/`ttl_expired`/`ttl_safety_net`/`infected`)
- [ ] Publish AMQP message
- [x] `DeleteExpiredFile` job (consumes the database queue) calling the service with `ttl_expired`
- [ ] `files:reap-expired` Artisan command + schedule entry (safety net), reason `ttl_safety_net`
- [ ] Tests: job execution deletes file; reaper catches an "orphaned" expired row

**M5 — RabbitMQ notification**
- [x] Scaffold `FileDeletedNotification`
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
