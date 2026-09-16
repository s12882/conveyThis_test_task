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

- [x] Routes: `GET /` → `FileUploadController@create` (named `upload.create`); `GET /files` → `FileController@index` (named `files.index`); `DELETE /files/{file}` → `FileController@destroy`; `POST /files` unchanged
- [x] Layout + partials + `<x-button>`/`<x-badge>` components — `resources/views/layouts/app.blade.php`, `partials/navbar.blade.php`, `partials/alerts.blade.php`, `components/button.blade.php`, `components/badge.blade.php`. Navbar uses plain `url()` links (not named routes) since `GET /`/`GET /files` aren't named yet — will switch to `route()` once M2/M3 name them.
- [x] M2 upload page: file input + client-side type/size pre-checks, jQuery AJAX submit (`FormData`) with progress bar, success/error alert handling — `resources/views/files/upload.blade.php`, `FileUploadController::create()`, `GET /` named `upload.create`
- [x] M3 file list page: paginated Bootstrap table (name/size/uploaded-at/expires-at/`scan_status` badge), delete via Bootstrap-modal-confirmed AJAX call — `resources/views/files/index.blade.php`, `files/partials/delete-modal.blade.php`, `FileController::index()`/`destroy()`
- [x] M3 sorting: sortable by `created_at`/`expires_at`/`size_bytes` via `sort_by`/`order` query params, whitelisted directly in the controller (not via `FormRequest` validation — see bug note below), default `created_at`/`desc`; clickable column headers (`files/partials/sort-link.blade.php`, first click ascending, click again toggles) with a ▲/▼ indicator; sort + `per_page` preserved across pagination via `$files->appends($request->query())`
- [x] `resources/js/app.js`: global CSRF `ajaxSetup`, upload-form handler (client-side extension/size pre-check driven by `data-*` attributes from `config('files.max_size_kb')`, progress bar, AJAX alert injection), delete-modal handler (`initDeleteModal()` — populates the shared modal from the clicked row's `data-*` attributes, AJAX `DELETE`, removes the row on success)
- [x] Tests: `tests/Feature/UploadPageTest.php` (M2 smoke test); `tests/Feature/FileControllerTest.php` (M3 — `index()` reflects DB state, invalid query params don't error, sorting actually reorders results, `destroy()` soft-deletes + removes the physical file, `destroy()` on an already-deleted file 404s)

**M2 done (2026-09-16).** Along the way, reviewed `StoreFileRequest` at the user's request and fixed two small issues: a dead `file.mimes` message key (rule is `mimetypes`, not `mimes` — that message could never fire) and the hardcoded `10240`/`"10 MB"` duplication (now both derive from a new `config('files.max_size_kb')`, backed by `FILE_MAX_SIZE_KB` in `.env`). Verified end-to-end via a real AJAX-shaped HTTP request (proper CSRF token + `X-Requested-With`/`Accept` headers, not just PHPUnit) — 201, file stored, DB record created. Full Feature suite: 17/17 passing.

**M3 done (2026-09-16).** Reviewed the user's already-in-progress `FileController`/`IndexRequest` first, as requested, and found two real bugs (both fixed with the user's go-ahead):
1. **`IndexRequest` made invalid sort params a hard failure** (`string|in:...` rules with no `sometimes`/fallback) — an explicitly-invalid `sort_by`/`order`/`per_page` (typo, stale bookmark, tampered URL) 302-redirected the user away from `/files` entirely (confirmed live via `curl`), rather than silently falling back to the default as the plan specified. `FormRequest` validation is the wrong tool for "normalize bad input silently" — fixed by dropping `IndexRequest` entirely and doing plain `in_array()`-whitelist-with-fallback directly in `FileController::index()` (missing params were already fine, confirmed empirically — only explicit invalid values were the problem).
2. **`destroy()` ignored `FileDeletionService::delete()`'s return value**, always responding `success: true`. Fixed (partly already done by the user) to check the boolean and respond with a `500` + `success: false` on failure.
3. **Found during verification, not in the original review**: `\Illuminate\Support\Number::fileSize()` (used for the size column) requires the `intl` PHP extension, which isn't installed in this project's Docker image — caused a real 500 (`ViewException`), not just the earlier boot-slowness red herring. Replaced with a small `File::human_size` accessor instead of adding a whole PHP extension for a cosmetic formatter.
4. **Found during verification**: manual delete (`FileController::destroy()`) soft-deleted the DB row correctly but left the physical file on disk — same root cause as the earlier `DeleteExpiredFile` disk-mismatch bug (`FileDeletionService::delete()` defaults its `$disk` param to `'public'`, but uploads live on `'local'`, and the call site didn't pass it explicitly). Fixed by passing `'local'` explicitly at the `FileController` call site, consistent with how the `DeleteExpiredFile` dispatch call was already fixed earlier this session.

All four confirmed via real end-to-end HTTP (not just PHPUnit): upload → appears in list with correct size/badge → sort links reorder correctly → delete → row disappears, DB row soft-deleted, physical file actually gone from disk. Full Feature suite: 22/22 passing.

**M4 — Shared deletion path + TTL**
- [x] `FileDeletionService`: delete physical file, soft-delete row with `deletion_reason` (`manual`/`ttl_expired`/`ttl_safety_net`/`infected`)
- [x] Publish AMQP message — see AMPQService fix below
- [x] `DeleteExpiredFile` job (consumes the database queue) calling the service with `ttl_expired`
- [x] `files:reap-expired` Artisan command + schedule entry (safety net), reason `ttl_safety_net` — `app/Console/Commands/ReapExpiredFiles.php`, `Schedule::command('files:reap-expired')->everyFiveMinutes()` in `routes/console.php`
- [x] Tests: `tests/Feature/ReapExpiredFilesTest.php` (3 tests: reaps an orphaned expired file, ignores not-yet-expired files, ignores already-deleted expired files)

**M4 done (2026-09-16).** Verified live: created a file with `expires_at` in the past (simulating a delayed job that never fired), ran `php artisan files:reap-expired` — correctly soft-deleted it, removed the physical file, set `deletion_reason=ttl_safety_net`, and published the AMQP deletion event.

**M5 — RabbitMQ notification**
- [x] `FileDeletedNotification` — user built the scaffold; this session fixed the two bugs that blocked it from actually working: the greeting referenced `$notifiable->name` (doesn't exist on the plain-email `Notification::route('mail', ...)` recipient we use, since there's no user/auth per decision 1) — replaced with a name-independent greeting; the action button linked to a nonexistent `/storage` route — pointed at `route('files.index')` instead. (`$fileData` itself was already fixed by the user before this session.)
- [x] `php-amqplib` integration: publisher (`AMPQService::publishFileDeletion()`, called from `FileDeletionService::delete()`) + queue declaration (see AMPQService fix below — no custom exchange needed, publishes straight to the `file_deletions` queue via RabbitMQ's default exchange)
- [x] `rabbitmq:consume-file-deletions` Artisan command — `app/Console/Commands/ConsumeFileDeletions.php`. Long-running `basic_consume` loop; sends `FileDeletedNotification` to `config('files.notification_email')` (new config key, backed by `FILE_DELETION_NOTIFICATION_EMAIL`) for each message; always acks (no dead-letter queue configured, so a malformed message is logged and dropped rather than retried forever); `--limit=N` option (with a short `wait()` timeout instead of blocking indefinitely) for deterministic testing without turning the command into a one-shot in production. New `rabbitmq-consumer` Docker Compose service runs it with no `--limit` (the long-running production mode deferred back in M0 until this command existed).
- [x] `MAIL_MAILER=log` config (already the Laravel default in this project, per M0 — nothing to change)
- [x] Tests: `tests/Feature/AMPQServiceIntegrationTest.php` (publishes against the **real** RabbitMQ, consumes it back, asserts the exact payload) and `tests/Feature/ConsumeFileDeletionsTest.php` (publishes a real message, runs the command with `--limit=1`, asserts the notification was sent via `Notification::fake()`/`assertSentOnDemand`; plus a "no messages waiting" graceful-exit case).

**Operational note:** the test suite hits the **real** RabbitMQ broker (no separate test instance), so a live `rabbitmq-consumer` container running at the same time will race the tests for messages on `file_deletions` and cause spurious failures — confirmed this by hitting exactly that failure after leaving the container running from manual verification. **Stop `rabbitmq-consumer` before running the test suite**, then start it again afterward. The same caveat doesn't apply to `queue-worker`/ClamAV, since those tests use `QUEUE_CONNECTION=sync`/the real (but non-competing) `clamd` daemon respectively.

**AMPQService fixed (2026-09-16).** The user asked me to investigate `Typed property App\Services\AMPQService::$connection must not be accessed before initialization` and finish the class. Root cause and fix:
- `$connection` was declared `protected AMQPStreamConnection $connection;` with no default and no constructor — PHP's typed properties are "uninitialized" (not null, not any default) until explicitly assigned, and reading an uninitialized typed property throws exactly this `Error`. Nothing in the class ever did `$this->connection = new AMQPStreamConnection(...)`. Fixed by constructing the connection in `__construct()`, using new `config('services.rabbitmq.*')` entries (host/port/user/password/`file_deletions_queue`, all already backed by the `.env` vars from M0).
- **Second, less obvious bug found while fixing the first**: `AppServiceProvider` registered `AMPQService` as a `singleton`. Since `publishFileDeletion()` closes its channel and connection at the end of every call, a *singleton* instance would have its connection closed after the first publish — then get reused (with a dead connection) by the next deletion within the same long-running process (the `queue-worker` container handles many jobs over its lifetime without restarting). Removed the singleton registration entirely; `AMPQService` has no unresolvable constructor dependencies, so Laravel auto-resolves a fresh instance (and fresh connection) on every `app(AMPQService::class)` call — exactly what's needed given the open-then-close-per-publish design.
- Replaced the hardcoded `'my_exchange'`/`'my_routing_key'`/`'my_queue'` placeholders and empty `publishMessage()` body with `publishFileDeletion(array $payload)`, publishing straight to the `file_deletions` queue (RabbitMQ's default nameless exchange, routing key = queue name — the simplest correct pattern for one producer/one queue, no custom exchange needed).
- `FileDeletionService::delete()`'s commented-out AMQP call re-enabled, now building a real payload (`file_id`, `original_name`, `size_bytes`, `deletion_reason`, `deleted_at`) instead of the old hardcoded `{'status':'success'}`.
- Verified via RabbitMQ's management API (manual publish + inspect) and via `AMPQServiceIntegrationTest` (automated): message lands on `file_deletions` with exactly the expected JSON payload, `delivery_mode: 2` (persistent).

Full Feature suite: 28/28 passing.

**M5 done (2026-09-16).** `docker-compose.yml` gained the `rabbitmq-consumer` service (deferred back in M0). Verified fully live: uploaded and deleted a real file through the actual web UI/AJAX flow while the long-running `rabbitmq-consumer` container was up (not a manual `--limit` run) — it automatically picked up the deletion event and logged the notification email, with the correct filename/size/reason and a working link back to `/files`. Remaining for M5/M6: nothing blocking — the pipeline (upload → scan/delete/TTL → AMQP → email) is complete end-to-end.

**Still outstanding (not a bug, just unfinished setup):** `FILE_DELETION_NOTIFICATION_EMAIL` in `.env`/`.env.example` is still the M0 placeholder (`notify@example.com`) — flagged back then and never changed. The consumer is fully functional now and really does send (logged) notifications to whatever this is set to, so it's worth setting to a real address before relying on it for anything beyond testing.

**M6 — Polish**
- [x] End-to-end manual verification via `docker compose up` (done repeatedly throughout M0–M5, each milestone verified against the real running stack)
- [x] README run instructions (services, ports, how to watch logs for the "sent" email) — rewrote `README.md` from the stock Laravel boilerplate: stack, how-it-works summary, prerequisites, getting-started steps (including `php artisan key:generate`, since `.env.example`'s `APP_KEY` is intentionally blank), services/ports table, watching the logged email, config var reference, running tests (with the `rabbitmq-consumer`-must-be-stopped caveat), known limitations
- [ ] Cleanup, error handling for edge cases (upload during low disk space, RabbitMQ temporarily down, etc.)

**Delete-modal JS bug found and fixed (2026-09-16), via Claude-in-Chrome browser testing on the user's report.** Reported symptom: deleting a file on `/files` worked (row removed, backend confirmed), but the confirmation modal stayed open, with `Uncaught ReferenceError: bootstrap is not defined` in the console.
- Reproduced live: uploaded a real file through the browser, deleted it, and captured the exact console error via `read_console_messages` — `ReferenceError: bootstrap is not defined`, thrown inside the AJAX `.done()` handler right after `$row?.remove()` (confirming the row-removal-then-crash sequence matched the report exactly) — a screenshot showed the table row gone but the modal backdrop still up.
- Root cause: `resources/js/app.js` had `import 'bootstrap/dist/js/bootstrap.bundle.min.js';` — a side-effect-only import. That's enough to register Bootstrap's own `data-bs-*` auto-behaviors (confirmed working independently — the modal's X/Cancel buttons closed it fine via Bootstrap's internal data-api, which doesn't need a JS-visible reference), but it does **not** create a `bootstrap` variable anywhere — so the app's own `bootstrap.Modal.getInstance(...)` calls (used to close the modal programmatically after a successful/failed delete) referenced a name that was never defined.
- Fix: changed the import to `import bootstrap from 'bootstrap/dist/js/bootstrap.bundle.min.js';` — same exact bundle (so all the already-working data-api behavior is untouched, and no new `@popperjs/core` ESM-resolution path is introduced), just captured as a proper default import this time, since Vite's CJS interop exposes a UMD bundle's `module.exports` as the default export.
- Re-verified via the same live browser flow: upload → delete → confirm in modal → modal now closes automatically, the "File deleted." success alert now shows too (it never could before, since the crash happened on the line just before it), and `read_console_messages` came back clean.
- No automated test added for this — this project has no JS test tooling, and standing one up for a one-line fix felt disproportionate; verified via live browser reproduction instead (screenshot + console capture, before and after).

**Nginx stale-upstream bug found and fixed (2026-09-16), during the user's own verification pass.** They reported `http://localhost:8000` returning 502 while RabbitMQ's UI worked fine. Root cause, confirmed via `docker compose logs webserver`: `connect() failed (111: Connection refused) ... upstream: "fastcgi://172.18.0.5:9000"`, but `docker inspect conveythis-app` showed the container's actual current IP was `172.18.0.6` — nginx was holding a stale IP for `app` from whenever it last started (15 hours earlier), because `docker/nginx/default.conf` used a static `fastcgi_pass app:9000;`, which nginx resolves once (at startup/reload) and caches for the life of the worker process. `app` (and `queue-worker`/`scheduler`/`rabbitmq-consumer`) had been recreated a few minutes prior — likely the user following the new README's getting-started steps or rebuilding after an `.env` change (a new `APP_KEY` appeared around the same time) — giving it a new internal IP that `webserver` (untouched, still 15h old) didn't know about.
- Immediate fix: `docker compose restart webserver` — resolved it right away.
- Structural fix, so this can't recur silently: added `resolver 127.0.0.11 valid=10s;` (Docker's embedded DNS) to `docker/nginx/default.conf` and switched `fastcgi_pass` from a static hostname to a variable (`set $upstream_app app:9000; fastcgi_pass $upstream_app;`) — this is the standard nginx-in-Docker pattern that forces periodic re-resolution instead of resolve-once-and-cache-forever. Verified: `nginx -t` (config valid), `nginx -s reload` (applied without a restart), app still reachable afterward. Tried to reproduce the original failure by force-recreating `app` again to get a fresh IP and confirm nginx self-heals without intervention — Docker kept reassigning the same IP in these particular recreates, so that specific reproduction wasn't conclusive, but the fix itself is a well-established, widely-used pattern, not something novel.
- Documented as a "Troubleshooting" section in `README.md` (what it looks like, why it happens, how to confirm the diagnosis) in case it's ever hit on a container that predates this fix.

## 6. Remaining open items for later milestones (not blocking start)

- Exact base Docker images / whether app + nginx are one container or two (default: two, `php-fpm` + `nginx`, unless you'd rather simplify).
- Whether the RabbitMQ management UI port should be exposed for convenience during development (default: yes, on 15672).
