# conveyThis — Async File Uploader

An async PDF/DOCX uploader built with Laravel, MySQL, RabbitMQ, ClamAV, and Bootstrap/jQuery. Files are scanned for viruses/macros, auto-expire after 24 hours, and every deletion (manual or automatic) triggers an email notification via a RabbitMQ-driven pipeline. See `TASK.md` for the original requirements and `IMPLEMENTATION.md` for the full design/decision log.

## Stack

- PHP 8.3 + Laravel 13
- MySQL 8.4
- RabbitMQ 3.13 (management plugin)
- ClamAV (`clamd`) for virus/macro scanning
- Bootstrap 5 + jQuery, built with Vite
- Docker Compose

## How it works

1. **Upload** (`/`) — a single PDF/DOCX (≤10MB) is validated (MIME type, filename encoding, structural integrity) and stored via AJAX. Every upload gets a 24h expiry and is queued for a virus/macro scan.
2. **Scanning** — a background job streams the file to ClamAV. Infected files are deleted immediately; clean files are marked `clean`.
3. **Manage Files** (`/files`) — a paginated, sortable list of uploads with a scan-status badge and a manual delete button (Bootstrap-modal-confirmed).
4. **Expiry** — each upload schedules its own delayed deletion job for ~24h later; a scheduled safety-net command also sweeps for any file that slipped through (e.g. a missed delayed job).
5. **Deletion → notification** — however a file is deleted (manual, TTL, or infected), the same service publishes an event to RabbitMQ. A long-running consumer picks it up and sends a notification email — logged, not actually delivered, per the task's requirements.

## Prerequisites

- Docker Desktop (with at least ~12GiB memory allocated — ClamAV alone wants 3–4GiB; see `IMPLEMENTATION.md` for why)
- No local PHP/Node/Composer install needed — everything runs in containers

## Getting started

```sh
cp .env.example .env   # already has working defaults for this Docker setup
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

Frontend assets need to be built once (and again after any `resources/js`/`resources/css` change) via a throwaway Node container, since the host has no Node installed:

```sh
docker run --rm -v "${PWD}:/app" -w /app node:20-alpine sh -c "npm install && npm run build"
```

Then visit:

| URL | What |
|---|---|
| http://localhost:8000/ | Upload page |
| http://localhost:8000/files | Manage Files page |
| http://localhost:15672 | RabbitMQ management UI (`guest`/`guest`) |

## Services & ports

| Service | Role | Port(s) |
|---|---|---|
| `app` | PHP-FPM (Laravel) | — |
| `webserver` | nginx | 8000 |
| `mysql` | Database | 3306 |
| `rabbitmq` | Broker + management UI | 5672, 15672 |
| `clamav` | `clamd` virus scanner | 3310 |
| `queue-worker` | `queue:work database` — TTL-deletion + scan jobs | — |
| `scheduler` | `schedule:work` — runs the TTL safety-net reaper every 5 min | — |
| `rabbitmq-consumer` | `rabbitmq:consume-file-deletions` — sends deletion-notification emails | — |

All services are on the `conveythis` Docker network and reach each other by service name (e.g. the app connects to `mysql`, `rabbitmq`, `clamav`).

## Watching the "sent" email

No real SMTP is configured (`MAIL_MAILER=log`, per the task's requirements) — every notification is rendered as a full email and written to the log instead of actually sent. To watch it happen:

```sh
docker compose logs -f rabbitmq-consumer   # confirms the consumer picked up the deletion event
docker compose exec app tail -f storage/logs/laravel.log   # the rendered email (From/To/Subject/HTML body) lands here
```

Trigger one: upload a file on `/`, then delete it on `/files` (or wait ~24h, or run `docker compose exec app php artisan files:reap-expired` to force the safety-net path).

## Configuration

Everything below is a `.env` var (see `.env.example` for defaults); the app-specific ones live in `config/files.php` and `config/services.php`:

| Variable | Purpose |
|---|---|
| `FILE_TTL_HOURS` | Hours before an upload auto-expires (default 24) |
| `FILE_MAX_SIZE_KB` | Max upload size in KB (default 10240 = 10MB) |
| `FILE_DELETION_NOTIFICATION_EMAIL` | Recipient for deletion-notification emails — **still the placeholder `notify@example.com`, set it to a real address** |
| `RABBITMQ_HOST`/`PORT`/`USER`/`PASSWORD` | Broker connection |
| `RABBITMQ_FILE_DELETIONS_QUEUE` | Queue name for deletion events (default `file_deletions`) |
| `CLAMAV_HOST`/`PORT` | `clamd` connection |

## Running tests

```sh
docker compose stop rabbitmq-consumer   # see note below
docker compose exec app php artisan test
docker compose up -d rabbitmq-consumer  # bring it back afterward
```

The suite hits real infrastructure rather than mocking everything — real MySQL, real ClamAV (using the industry-standard EICAR test string), and real RabbitMQ. **Stop `rabbitmq-consumer` before running tests**: since there's no separate test broker, a live consumer will race the tests for messages on the same queue and cause spurious failures.

## Notes / known limitations

- No authentication — the file list is shared and unauthenticated, per the task's scope.
- No download/preview of uploaded files by design — the app only lists and deletes.
- `FILE_DELETION_NOTIFICATION_EMAIL` ships as a placeholder; set it before relying on notifications for anything beyond local testing.
- See `IMPLEMENTATION.md` for the full milestone-by-milestone build log, architecture decisions, and bugs found/fixed along the way.

## Troubleshooting

**`http://localhost:8000` returns 502, other services are fine.** Almost always nginx holding a stale upstream IP for the `app` container — happens if `app` gets rebuilt/recreated (`docker compose up -d --build app`, etc.) without also touching `webserver`. `docker/nginx/default.conf` resolves `app` through Docker's embedded DNS (`resolver 127.0.0.11 valid=10s;` + a variable-based `fastcgi_pass`) specifically so this self-heals within ~10s — but if you're still on an older container that predates that fix, `docker compose restart webserver` clears it immediately. Confirm the diagnosis via `docker compose logs webserver | grep "connect() failed"` — it'll show an upstream IP that no longer matches `docker inspect conveythis-app --format '{{.NetworkSettings.Networks.conveythis_test_task_conveythis.IPAddress}}'`.
