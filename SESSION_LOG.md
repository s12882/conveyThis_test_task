# Session Log — conveyThis_test_task

Running log of interaction and work process for this Claude Code session.
Updated as we go; most recent entries at the bottom.

---

## 2026-09-15 — Session start

**Context discovered:**
- Working directory: `C:\Users\whool\.local\bin\conveyThis_test_task`
- Not yet a git repository.
- Laravel application skeleton present (`app/`, `routes/`, `config/`, `artisan`, `composer.json`, `vendor/` already installed).
- `CLAUDE.md` / `AGENTS.md` contain Laravel Boost bootstrap instructions (PHP/Composer check → install `laravel/boost` → `php artisan boost:install`). Boost does not appear to have been run yet (AGENTS.md still shows the generic bootstrap text rather than generated guidelines).
- `TASK.md` defines the actual assignment:
  - **Stack:** PHP + Laravel, MySQL, RabbitMQ, Bootstrap + jQuery (FE), Docker.
  - **File upload:** async uploader for PDF/DOCX via web UI, 10MB size limit, file metadata stored in MySQL.
  - **Pages:** a file-management page — list uploaded files, delete manually.
  - **Business logic:** 24h TTL on files; on deletion (auto via TTL or manual) publish a RabbitMQ message that triggers an email notification to the address in `.env` (actual SMTP sending not required — just the mechanism/log).

**Action taken:**
- User asked to keep a session log — created this file (`SESSION_LOG.md`) at project root to track interaction and work process going forward.

**Status:** Awaiting direction on how to proceed (e.g., run Laravel Boost bootstrap first, or scaffold the file-upload feature directly).

---

## 2026-09-15 — Implementation plan drafted

**User request:** Read `TASK.md` and produce a general `IMPLEMENTATION.md` plan to expand on later, flagging any ambiguity/branching approaches as questions.

**Investigation:**
- Confirmed this is a fresh `laravel/laravel` skeleton (Laravel 13, PHP ^8.3), no auth scaffolding, Tailwind wired in `package.json` (task wants Bootstrap + jQuery instead), no Docker files, no RabbitMQ package yet.
- `.env` has standard Laravel defaults (SQLite-capable DB config commented out, `QUEUE_CONNECTION`, `MAIL_MAILER`, no dedicated "notification recipient" var yet — will need one per task's "email specified in .env").

**Action taken:**
- Created `IMPLEMENTATION.md` with: current-state summary, architecture sketch (app, MySQL, storage, TTL enforcer, RabbitMQ, notification consumer, Docker Compose), a draft `files` table schema, a phased milestone plan (M0 scaffolding → M1 upload backend → M2 upload frontend → M3 management page → M4 TTL expiry → M5 RabbitMQ/notification → M6 polish), and 9 open questions covering: auth/ownership, RabbitMQ integration style (Laravel queue driver vs raw AMQP), how "sending" the email should be represented, TTL enforcement mechanism (scheduler scan vs per-file delayed job), what "asynchronous upload" means here, whether download/preview is in scope, single vs multi-file upload, testing expectations, and Docker container topology for the worker/scheduler.

**Status:** Waiting on the user's answers to the 9 open questions in `IMPLEMENTATION.md` before expanding it into a detailed task breakdown.

---

## 2026-09-15 — Architecture decisions locked in, plan expanded

**User answers to the 9 open questions:**
1. No auth. 2. RabbitMQ via raw AMQP (`php-amqplib`), not a Laravel queue driver. 3. Notification sent as a real `Mailable` through the `log` mail driver. 4. TTL via a per-file delayed job (dispatched at upload time, fires ~24h later). 5. "Async" = AJAX request, synchronous storage within that request. 6. File page = list + manual delete only, no download/preview. 7. Single-file upload only. 8. Cover with automated PHPUnit tests. 9. Separate Docker containers for the queue worker and the scheduler.

**Reconciling 2+4+9:** Since RabbitMQ isn't Laravel's queue driver, TTL deletion still needs *some* Laravel queue for the delayed job — decided to use `QUEUE_CONNECTION=database` (the `jobs` table) purely for that delayed job, kept entirely separate from RabbitMQ. That gives three distinct async workers to containerize: `queue-worker` (runs the DB-queue delayed TTL job), `rabbitmq-consumer` (long-lived Artisan command consuming AMQP messages, sends the logged email), and `scheduler` (`php artisan schedule:work`, acting as a safety-net reaper for any file whose delayed job didn't fire — e.g. lost across a restart/deploy). Manual delete, the delayed TTL job, and the safety-net reaper all funnel through one shared `FileDeletionService` so the "delete file + publish RabbitMQ event" logic lives in one place.

**Action taken:**
- Rewrote `IMPLEMENTATION.md`: added a decisions table, a finalized architecture section (with a small ASCII flow diagram), a firmed-up `files` table schema, an explicit list of the 7 planned Docker Compose services, and expanded the milestones (M0–M6) into checklist-style tasks that include their own tests per decision 8 rather than deferring all testing to the end.
- Left two minor non-blocking items for later (noted in the doc): whether app+nginx are one container or two, and whether to expose the RabbitMQ management UI port during development.

**Status:** Plan is ready to start implementation at M0 (Docker/env scaffolding) — awaiting the user's go-ahead to begin coding.

---

## 2026-09-15 — TASK.md change tracking set up

**User request:** Keep watching `TASK.md` for edits going forward; if a change is ambiguous, ask how to handle it rather than assuming.

**Action taken:**
- Recorded a baseline snapshot of `TASK.md` to diff future reads against:
  - **SHA256:** `d6bc2c33794a2d72d36484091fd7af9a47ffbfa077652b3ba8eb26fbfa3dab9d`
  - Content as of this baseline matches what's summarized in `IMPLEMENTATION.md` §1 (stack, file upload, pages, business logic — unchanged since session start).
- Saved cross-session memory (`track_task_md_changes`) so future sessions in this project also re-check `TASK.md` against this log before proceeding, and a pointer memory (`implementation_plan_conveythis`) summarizing the locked-in architecture decisions so a fresh session can reorient quickly.

**Process going forward:** Before/during implementation work, re-hash `TASK.md`; if it differs from the last recorded hash here, diff the content, note unambiguous changes in this log, and ask the user before acting on anything ambiguous or that conflicts with a locked-in decision in `IMPLEMENTATION.md`. Update the recorded hash after each check.

**Status:** Baseline recorded, no changes detected yet. Still awaiting go-ahead to start M0.

---

## 2026-09-15 — M0: Docker & environment scaffolding

**User request:** Start M0 (environment/Docker setup), step by step so progress can be verified in between steps. Docker was already confirmed running on the host.

**TASK.md check:** hash unchanged (`d6bc2c33...dab9d`) — no drift from baseline.

**Files created:**
- `docker/php/Dockerfile` — multi-stage build: Node 20 stage runs `npm install && npm run build` for frontend assets, PHP stage is `php:8.3-fpm` with `pdo_mysql`, `zip`, `bcmath`, `pcntl`, `opcache` extensions, Composer copied in from the official `composer:2` image, `composer install` run at build time, runs as `www-data`.
- `docker/nginx/default.conf` — nginx vhost proxying PHP to `app:9000` via fastcgi, serving `public/`.
- `docker-compose.yml` — services: `app` (php-fpm), `webserver` (nginx, published on `8000:80`), `mysql:8.4` (healthchecked, published `3306`), `rabbitmq:3.13-management-alpine` (healthchecked, `5672`/`15672` published), `queue-worker` (`php artisan queue:work database`), `scheduler` (`php artisan schedule:work`). `rabbitmq-consumer` intentionally deferred until M5 — its Artisan command doesn't exist yet, and adding the service now would just crash-loop.
  - App/worker/scheduler containers bind-mount the repo (`.:/var/www/html`) for live PHP edits, but use named volumes (`app-vendor`, `app-public-build`) overlaid on `vendor/` and `public/build/` so the image-baked Composer/Vite output isn't hidden by the (currently empty, on this host) host-side equivalents.
- `.dockerignore` — excludes `vendor`, `node_modules`, `public/build`, logs, `.env`, etc. from the build context.

**`.env` / `.env.example` changes:**
- `DB_CONNECTION` switched from `sqlite` to `mysql`, pointed at the `mysql` service (`DB_HOST=mysql`, database/user/password `conveythis`/`conveythis`/`secret`).
- Added `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` (guest/guest — default RabbitMQ dev creds), `RABBITMQ_FILE_DELETIONS_QUEUE=file_deletions`.
- Added `FILE_TTL_HOURS=24` and `FILE_DELETION_NOTIFICATION_EMAIL=notify@example.com` (placeholder — **needs a real value from the user before M5**).
- `QUEUE_CONNECTION=database` and `MAIL_MAILER=log` were already the Laravel 13 skeleton defaults — no change needed there.

**Problem encountered — corrupted Docker base image layer:**
- First `docker compose up` attempt: `app`/`queue-worker`/`scheduler` containers exited immediately with `exec /usr/local/bin/docker-php-entrypoint: exec format error`; `webserver` failed separately because `app` wasn't up (`host not found in upstream "app"` — expected, self-resolves once `app` is healthy).
- Root-caused: the locally cached `php:8.3-fpm` base image had a **0-byte** `docker-php-entrypoint` script inside it — corrupted, most likely from the Docker Desktop engine crash that happened earlier this session mid-build (see prior log entry, "Docker Desktop engine appears to have crashed"). `docker rmi` + `docker pull` kept reporting "Already exists" and reusing the same corrupted on-disk blob rather than re-downloading, so simple re-pulls didn't fix it.
- First fix attempt: switched the Dockerfile's base image to `php:8.3-fpm-alpine` to sidestep the corrupted Debian-variant blob entirely (confirmed the Alpine entrypoint script was intact at 122 bytes). **User rejected this** — asked to roll back to `php:8.3-fpm` and try fixing the actual corruption instead, since they'd already run a `docker system prune` on their end and had no other images they needed to preserve.
- Reverted the Dockerfile to the original Debian-based `php:8.3-fpm`. After the user's prune, `docker images` showed nothing cached; a fresh `docker pull php:8.3-fpm` then downloaded all layers cleanly and the entrypoint script verified intact (122 bytes) — confirming the corruption is resolved now that the bad cache is gone.

**Verification (all passed):**
- Rebuilt `app`, `queue-worker`, `scheduler` images on the clean `php:8.3-fpm` base — built successfully.
- `docker compose up -d`: all 6 containers reached `Up`/`healthy`.
- `queue-worker` initially exited (`SQLSTATE[42S02]: Base table ... 'cache' doesn't exist`) — expected, since migrations hadn't run yet against the fresh MySQL volume. Ran `docker compose exec app php artisan migrate --force` (creates `users`, `cache`, `jobs` tables), then `docker compose restart queue-worker` — came up clean.
- `curl http://localhost:8000/` → HTTP 200, `<title>Laravel</title>` (welcome page rendering with Vite-built assets).
- `curl http://localhost:15672/` (RabbitMQ management UI) → HTTP 200.
- `php artisan tinker` inside the `app` container confirmed a live PDO connection to MySQL.
- `scheduler` logs show `Running scheduled tasks` / `No scheduled commands are ready to run` (expected, none defined yet).

**Status:** M0 core Docker/env checkpoint complete and verified. Remaining M0 item: swap Tailwind for Bootstrap + jQuery in the frontend build. `rabbitmq-consumer` service deliberately deferred to M5. `FILE_DELETION_NOTIFICATION_EMAIL` still a placeholder — need the real address from the user before M5. Next: awaiting go-ahead to do the Bootstrap/jQuery swap, then move to M1 (upload backend).

---

## 2026-09-15 — M0: Bootstrap + jQuery swap (completes M0)

**User request:** Go ahead with the Tailwind → Bootstrap/jQuery swap.

**TASK.md check:** hash unchanged (`d6bc2c33...dab9d`).

**Changes:**
- `package.json` — removed `tailwindcss`/`@tailwindcss/vite`; added `bootstrap ^5.3.3` and `jquery ^3.7.1` as regular dependencies.
- `vite.config.js` — dropped the `tailwindcss()` plugin and the `bunny` font plugin (was Tailwind-design-specific, not needed for a Bootstrap admin-style UI).
- `resources/css/app.css` — now just `@import 'bootstrap/dist/css/bootstrap.css';` (was the Tailwind v4 `@import`/`@theme`/`@source` block).
- `resources/js/app.js` — imports jQuery and assigns it to `window.$`/`window.jQuery` (so classic jQuery-plugin-style code works), then imports `bootstrap/dist/js/bootstrap.bundle.min.js` for Bootstrap's JS (dropdowns, modals, etc., Popper included).
- `resources/views/welcome.blade.php` — replaced Laravel's stock Tailwind marketing page with a minimal Bootstrap navbar + card placeholder (a "File uploader" heading, a note that upload/management pages land in later milestones, and a jQuery-wired button toggling a Bootstrap alert — proves both libraries are working together). This view becomes the real app shell in M2/M3.

**Problem encountered — stale build output from named volumes:**
- After rebuilding the `app`/`queue-worker`/`scheduler` images and recreating containers, the served CSS/JS were still the *old Tailwind* build (same file hashes as the very first build). Root cause: `docker-compose.yml` used named volumes (`app-vendor`, `app-public-build`) mounted over `vendor/` and `public/build/` specifically so the image's baked Composer/Vite output wouldn't be hidden by the (then-empty) host directories — but named volumes only seed from the image **once, on first creation**; every rebuild since was silently ignored because the volumes already existed.
- Fix: removed both named volumes from all four services (`app`, `webserver`, `queue-worker`, `scheduler`) and from the top-level `volumes:` block in `docker-compose.yml`, then deleted the orphaned volumes (`docker volume rm ..._app-vendor ..._app-public-build`). Going forward, `vendor/` and `public/build/` are plain parts of the bind-mounted host directory — real, host-visible directories — not volume overlays.
- Since the bind mount now fully governs those paths, the host needed real content in them: `vendor/` already existed on the host from before this session. `public/build/` didn't — built it by running `npm install && npm run build` inside a throwaway `node:20-alpine` container with the project directory bind-mounted (`docker run --rm -v "${PWD}:/app" -w /app node:20-alpine sh -c "npm install && npm run build"`), so no Node install is needed on the host itself. (Had to use the PowerShell tool for this specific command — Git Bash/MSYS was mangling the `-w /app` working-directory flag into a bogus Windows path.)
- **Consequence to remember:** the Dockerfile's own asset-build stage (Node stage → `public/build` baked into the image) is still there and correct for a fresh, no-bind-mount deployment, but during local dev the bind mount shadows it. After any `package.json`/frontend change, must re-run the throwaway-container `npm run build` (or `docker compose exec` into a container that has Node — none of our long-running containers do) before the running app reflects it. Composer changes need the equivalent `composer install` run against the host `vendor/` (host already has PHP/Composer per earlier `ls` evidence, or run it via a throwaway `composer:2` container the same way).

**Verification (all passed):**
- Rebuilt images, recreated containers, all 6 still `Up`/`healthy`.
- `curl http://localhost:8000/` → 200; response HTML contains the new navbar/card/`ping-button` markup.
- Fetched the built CSS bundle — contains Bootstrap's `--bs-*` CSS custom properties.
- Fetched the built JS bundle — contains `jQuery`, `3.7.1`, and `bootstrap` identifiers.

**Status:** M0 fully complete (all checklist items in `IMPLEMENTATION.md` §5 checked off). Next: awaiting go-ahead for M1 (upload backend — migration, model, upload endpoint, delayed TTL job dispatch).

---

## 2026-09-15 — Upload edge-case analysis (plan mode): encoding & virus/macro scanning

**User request (plan mode):** Before starting M1, analyze `mb_check_encoding` (for wrong/unreadable encoding) and `adriengras/php-clamav` (for viruses/macros) — pros/cons and integration issues for this stack.

**TASK.md check:** hash unchanged (`d6bc2c33...dab9d`).

**Research done:** checked `mbstring` is already loaded in the app container (`php -m`) despite not being explicitly installed in the Dockerfile — ships enabled by default in `php:8.3-fpm`. Checked `ext-sockets` is *not* loaded (would need adding). Web-searched `adriengras/php-clamav` (Packagist/GitHub) and fetched its README: v1.0.0 (Nov 2024), MIT, requires `ext-sockets`, low adoption (3 GitHub stars), API includes `scan()`/`scanInStream()`/`ping()`, supports TCP or Unix socket to `clamd`. Also searched for Laravel-ecosystem alternatives (`sunspikes/clamav-validator`, `digitalideastudio/clamav-validator`).

**Key analysis point:** the two tools solve unrelated problems — `mb_check_encoding` only validates strings (the original filename, not file content) and can't detect corrupted files, viruses, or macros; ClamAV via `clamd` is the right tool for the virus/macro concern specifically because its signature DB covers Office-macro and PDF-exploit heuristics too, not just classic viruses.

**Branching decisions, resolved via `AskUserQuestion`:**
1. Invalid-UTF-8 filename → **reject upload (422)**.
2. ClamAV scope → **integrate real ClamAV now** (not a stub), despite the added Docker resource cost (new `clamav` service, ~1-3GB RAM, signature-DB download needing internet on first boot).
3. ClamAV client → **`sunspikes/clamav-validator`-style package** (over the originally-asked-about `adriengras/php-clamav`, and over writing a custom client).
4. Scan timing → user pushed back on my original fail-open/fail-closed framing and asked directly whether async scanning + a "verified" status is even meaningful for protection. Answered: it's a *detection/cleanup/audit* control rather than a *preventive* one, but that distinction barely matters here specifically because decision 6 (M0) already rules out any download/preview feature — so there's no window where a not-yet-scanned file is actually exposed to anyone. User chose **async via queue + `scan_status` column**, reusing the existing database queue-worker and `FileDeletionService`/RabbitMQ pipeline (infected → `deletion_reason='infected'`, same notification path as any other deletion).

**Integration wrinkle surfaced:** `sunspikes/clamav-validator`-style packages are built around Laravel's synchronous `Validator::extend` — that only fits a blocking-scan design. Since we're going async, implementation will need to call the package's underlying clamd-socket client directly from a queued job rather than through its validation-rule wrapper; noted as a to-confirm-in-code detail, with a ~30-line custom INSTREAM client as the documented fallback if that's not cleanly reusable standalone.

**Plan written and approved:** `~/.claude/plans/before-starting-implementation-i-nested-lerdorf.md`. Folded into `IMPLEMENTATION.md`: new `scan_status`/`scanned_at` columns and a fourth `deletion_reason` value (`infected`) in §4; a new `clamav` Docker service in §3's service list; a new **M1.5 — Virus/macro scanning (ClamAV)** milestone (sockets extension, `clamav` service, `CLAMAV_HOST`/`CLAMAV_PORT` env vars, ClamAV client package, `ScanUploadedFile` job, tests); M1 gained the encoding + structural-validity checks; M3's list page gained a `scan_status` badge; M4's `FileDeletionService` bullet gained the `infected` reason.

**Status:** Exited plan mode with the plan approved. Encoding/scanning design is now folded into `IMPLEMENTATION.md`. Still need to confirm with the user how they want to sequence the now-larger M1 implementation (step-by-step like M0, and in what order) before writing code.

---

## 2026-09-16 — M1.5 infra: ClamAV stood up and verified

**User request:** Sequence M1/M1.5 as "infra first, then app code" (chose this over app-code-first or one combined pass).

**Blocker found before starting:** official ClamAV docs list 3GiB minimum / 4GiB preferred RAM for `clamd`; Docker Desktop's VM was only allocated ~7.7GiB total (shared across every other service). Flagged this to the user rather than proceeding — asked whether to bump Docker's memory limit, proceed as-is and see what happens, or use a reduced-memory ClamAV config. **User chose to bump the limit.** I can't change Docker Desktop's GUI settings myself, so gave them the manual steps (Settings → Resources → Advanced → raise Memory slider, Apply & restart — recommended ~12GiB given the host has ~16GiB total). User did this and confirmed; verified afterward (`docker info`) that the VM now reports 11.68GiB, up from 7.71GiB.

**TASK.md check:** hash unchanged (`d6bc2c33...dab9d`) at both the start and after the memory-bump wait.

**Changes:**
- `docker/php/Dockerfile` — added `sockets` to the `docker-php-ext-install` list (shared by `app`/`queue-worker`/`scheduler`).
- `docker-compose.yml` — new `clamav` service (`clamav/clamav:1.5` — this tag ships with a pre-baked signature DB, so first boot didn't need a lengthy `freshclam` download; picked over the `_base` variant for that reason), with a persistent `clamav-db` volume, port `3310` published, and a healthcheck using the image's bundled `clamdcheck.sh` (generous `start_period: 600s` in case a future pull *does* need a full signature download). `queue-worker` now has `depends_on: clamav: condition: service_healthy`.
- `.env` / `.env.example` — added `CLAMAV_HOST=clamav`, `CLAMAV_PORT=3310`.

**Verification (all passed):**
- Rebuilt `app`/`queue-worker`/`scheduler` images, recreated all containers — all healthy, including `clamav` (came up healthy well within the timeout thanks to the pre-baked signature DB).
- `php -m` in the `app` container confirms `sockets` loaded.
- Wrote and ran an inline PHP script using `ext-sockets` to `PING` clamd directly → `PONG`.
- Wrote and ran an inline PHP script implementing clamd's `zINSTREAM` protocol (length-prefixed chunks over the socket) and tested it against the industry-standard **EICAR test string** → correctly flagged (`stream: Eicar-Test-Signature FOUND`); tested a harmless string → correctly passed (`stream: OK`). Confirms the exact mechanism `ScanUploadedFile` will use works end-to-end, and gives a working ~15-line reference implementation in case `sunspikes/clamav-validator`'s internals turn out not to be cleanly reusable standalone (per the plan's documented fallback).
- `clamav` startup log confirms PDF, XMLDOCS (OOXML/DOCX), and OLE2 (legacy Office/macro) scanning support all enabled — directly covers this project's file types and the macro concern that motivated this whole detour.

**Status:** M1.5 infra complete and verified. Next: move to M1 app code (migration incl. `scan_status`/`scanned_at`, `File` model, upload endpoint with encoding/structural/MIME/size validation, `DeleteExpiredFile` + `ScanUploadedFile` job dispatch) and M1.5 app code (the `ScanUploadedFile` job itself, ClamAV client package decision).

---

## 2026-09-16 — M1: `files` migration

**User request:** Go ahead with the migration files first (step-by-step within M1, matching the established M0 cadence).

**TASK.md check:** hash unchanged (`d6bc2c33...dab9d`).

**Work:** Generated `database/migrations/2026_09_15_210808_create_files_table.php` via `php artisan make:migration create_files_table --create=files` (container clock is a day behind host date at generation time — cosmetic only, ordering vs. the existing `0001_01_01_*` migrations is unaffected), then filled in the schema per `IMPLEMENTATION.md` §4: `original_name`, `stored_path`, `mime_type` (strings), `size_bytes` (unsigned int), `expires_at` (timestamp, indexed — supports the M4 safety-net reaper's query), `deletion_reason` (enum: `manual`/`ttl_expired`/`ttl_safety_net`/`infected`, nullable), `scan_status` (enum: `pending`/`clean`/`infected`/`error`, default `pending`), `scanned_at` (nullable timestamp), plus `timestamps()` and `softDeletes()`.

**Verification:** ran `migrate --force` — applied cleanly; inspected the live schema via `Schema::getColumns('files')` in tinker and confirmed every column/type/nullability matches the design exactly; ran `migrate:rollback --step=1` then `migrate` again to confirm `down()` also works cleanly, and left the migration applied.

**Status:** Migration done and verified. Next (pending go-ahead): `File` model, then the upload `FormRequest`/endpoint.

---

## 2026-09-16 — M1: `File` model

**User request:** Go ahead with the `File` model.

**TASK.md check:** hash unchanged (`d6bc2c33...dab9d`).

**Work:** `php artisan make:model File --factory` → `app/Models/File.php` (added `SoftDeletes`, `$fillable` for all columns, `casts()` for `expires_at`/`scanned_at` as `datetime` and `size_bytes` as `integer`) and `database/factories/FileFactory.php` (random pdf/docx original name + matching mime type, `stored_path` under `uploads/`, size within the 10MB range, `expires_at` = +24h, `scan_status` defaults `pending`; added `expired()`, `clean()`, `infected()` factory states since M1/M1.5/M4 tests will need exactly those scenarios).

**Verification:** via tinker — created a factory instance, confirmed `expires_at` casts to `Illuminate\Support\Carbon`; called `->delete()` and confirmed `trashed()` is true, the row is invisible to a default `File::find()` but still present via `withTrashed()` — soft delete behavior confirmed correct end-to-end against the real MySQL container. Cleaned up the test row afterward (`forceDelete`).

**Status:** Model + factory done and verified. Next (pending go-ahead): the upload `FormRequest`/endpoint (MIME/size validation + the encoding/structural checks from the edge-case plan).
