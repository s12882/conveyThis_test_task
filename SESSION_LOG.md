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
