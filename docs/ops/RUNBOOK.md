# Operations Runbook — Cameroon Timber Hub

Production host: `www.cameroontimberhub.com` · app root `/home/timberhub/htdocs/www.cameroontimberhub.com` · runs as user `timberhub` · PHP 8.3-FPM · PostgreSQL 16 · Redis · nginx.

SSH: `ssh -i ~/.ssh/cameroontimberhub_deploy root@www.cameroontimberhub.com` (the `timberhub` user has no direct SSH; `sudo -u timberhub` for app commands).

---

## 1. Health & monitoring

| Signal | Where |
|---|---|
| Liveness | `GET /up` (Laravel default — 200 = the app booted) |
| Readiness | `GET /up/health` — JSON `{status, checks:{database,cache,queue,scheduler}, time}`; **503** if any check fails (`queue` = backlog on the configured connection < 10k; `scheduler` = heartbeat < 3 min old). Point the uptime monitor here. |
| Errors | `storage/logs/errors-YYYY-MM-DD.log` (dedicated `errors` channel, 30-day retention). Every unhandled exception is reported here in addition to the normal log. |
| Daily error digest | Emailed 07:00 to `config('mail.ops_address')` (`MAIL_OPS_ADDRESS`, falls back to `MAIL_FROM_ADDRESS`) by `php artisan ops:error-digest`. |
| Queue health | `php artisan ops:queue-health` runs every 15 min; warns on the `errors` channel when `failed_jobs` grows or the oldest pending job is > 5 min old (worker / outbox-relay starvation). Run it by hand any time for a summary line. |
| CSP violations | `POST /csp-report` logs to the `errors` channel at `warning`. The policy is **Report-Only** today — collect a week, then tighten `SecurityHeaders::CSP` and switch the header to enforcing. |
| N+1 lazy loads | `Model::preventLazyLoading()` is on. Throws only in `local`; in production/CI it logs `N+1: lazy-loaded [rel] on [Model]` to the `errors` channel. **Known backlog to fix** (found by the Task A5 audit, low-traffic paths): `Order::rfq` in the reorder/review surface, `Company::plan` in `RfqMatchingService`, `Company::verification` in `ComputeRiskAssessmentsCommand`. The hot-path ones (`Product::images` on every marketplace/search/domestic listing, `TradeAssuranceMilestone::agreement` on milestone confirm) are already fixed. |
| Live smoke | `curl -sI https://www.cameroontimberhub.com/` (expect 200 + the X-* / HSTS headers), `curl -s https://www.cameroontimberhub.com/api/v1/products?per_page=1` (expect `{"data":[…]}`). |

---

## 2. Deploy

From a machine with the deploy key (commit + push to `origin/master` first):

```bash
ssh -i ~/.ssh/cameroontimberhub_deploy root@www.cameroontimberhub.com \
 'cd /home/timberhub/htdocs/www.cameroontimberhub.com && \
  sudo -u timberhub git pull --ff-only origin master && \
  sudo -u timberhub composer install --no-dev --optimize-autoloader && \
  sudo -u timberhub php artisan migrate --force && \
  sudo -u timberhub php artisan config:cache && \
  sudo -u timberhub php artisan route:cache && \
  sudo -u timberhub php artisan view:cache && \
  sudo -u timberhub php artisan event:cache && \
  sudo -u timberhub php artisan filament:cache-components && \
  sudo -u timberhub php artisan scramble:clear && \
  sudo -u timberhub php artisan queue:restart && \
  systemctl reload php8.3-fpm && \
  systemctl restart timberhub-queue.service'
```

- `migrate --force` is safe — every migration in this repo is additive. If a migration is destructive, **take a backup first** (§4).
- `queue:restart` + a `timberhub-queue.service` restart are both needed: the signal tells running workers to finish and exit, the service restart brings them back with the new code.
- After deploy: `GET /up/health` should be `ok`; `php artisan about --only=cache` should show every cache `CACHED`.

## 3. Rollback

```bash
ssh -i ~/.ssh/cameroontimberhub_deploy root@www.cameroontimberhub.com \
 'cd /home/timberhub/htdocs/www.cameroontimberhub.com && \
  sudo -u timberhub git reset --hard <last-good-sha> && \
  sudo -u timberhub composer install --no-dev --optimize-autoloader && \
  sudo -u timberhub php artisan config:cache route:cache view:cache event:cache && \
  sudo -u timberhub php artisan filament:cache-components && \
  sudo -u timberhub php artisan queue:restart && \
  systemctl reload php8.3-fpm && systemctl restart timberhub-queue.service'
```

A migration that ran and now needs reverting: `php artisan migrate:rollback --step=1 --force` **only if** its `down()` is safe. Prefer rolling forward with a fix.

---

## 4. Backups & restore

**Backup:** `deploy/backup.sh` (installed from `deploy/cron`, daily 02:00) writes `cth-db-<stamp>.dump` (`pg_dump -Fc`) **and** `cth-files-<stamp>.tar.gz` (`storage/app` — uploaded documents, certificates, media; excludes framework caches/logs/livewire-tmp) to `/home/timberhub/backups`, keeps `BACKUP_RETENTION_DAYS` (default 14) days, and copies both offsite with `rclone` when `BACKUP_RCLONE_REMOTE` is set. DB-only backups lose every uploaded document — always restore both.

> **TODO (owner decision):** confirm the offsite target (S3 bucket / second host / Hostinger snapshot) and set `BACKUP_RCLONE_REMOTE` in the cron environment. Until then a single-host failure loses everything.

**Restore drill (against a scratch DB — never the live one without a maintenance window):**

```bash
createdb cth_restore_test
pg_restore -d cth_restore_test --clean --if-exists /home/timberhub/backups/cth-db-<stamp>.dump
psql cth_restore_test -c "select count(*) from orders; select count(*) from companies;"
mkdir /tmp/files-restore && tar -xzf /home/timberhub/backups/cth-files-<stamp>.tar.gz -C /tmp/files-restore && ls /tmp/files-restore/app
dropdb cth_restore_test && rm -rf /tmp/files-restore
```

Record the drill date + row counts here each time one is run:

| Date | Backup file | orders | companies | Result |
|---|---|---|---|---|
| _(pending first drill)_ | | | | |

---

## 5. Common tasks

| Need | Command (`sudo -u timberhub php artisan …`) |
|---|---|
| Clear all caches | `optimize:clear` |
| Rebuild caches | `config:cache route:cache view:cache event:cache && filament:cache-components` |
| Re-run scheduled tasks list | `schedule:list` (verify the outbox relay + digests + queue-health are registered) |
| Check outbox backlog | `php artisan tinker --execute="echo App\Models\OutboxEvent::whereNull('published_at')->count();"` |
| Retry failed jobs | `queue:retry all` (inspect first: `queue:failed`) |
| Verify audit-log integrity | `activitylog:verify-chain` (exit 1 = tampering) |
| Rotate a webhook secret | exporter panel → Webhooks → the subscription → regenerate (shows once) |
| Rotate the AI provider key | two-person + fresh-2FA flow in `/admin` → AI API key changes |

Cron (as `timberhub`, file: `deploy/cron`): `* * * * * cd /home/timberhub/htdocs/www.cameroontimberhub.com && /usr/bin/php artisan schedule:run >> /dev/null 2>&1` — **this must exist** or the outbox relay, digests, badge expiry and queue-health all stop silently. Verify: `crontab -l -u timberhub`; `/up/health` reports `checks.scheduler: false` (503) when the every-minute `ops:scheduler-heartbeat` is > 3 min stale.

Queue worker (unit file: `deploy/systemd/timberhub-queue.service`): `systemctl status timberhub-queue.service` — must be `active (running)`. Logs: `journalctl -u timberhub-queue -n 100`.

---

## 5a. Go-live checklist

Work through in order on the production host. Every step is re-runnable.

1. **Environment** — copy the commented *PRODUCTION* block at the bottom of `.env.example` into `.env` and fill it in: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://www.cameroontimberhub.com`, `APP_KEY` (generate **once**, store in the password manager), `TRUSTED_PROXIES` (loopback for nginx on the same host; the Cloudflare/LB CIDRs or `*` if traffic comes through one), `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=redis`, `MAIL_MAILER=smtp` + real SMTP creds + `MAIL_FROM_ADDRESS` on our domain, `MAIL_OPS_ADDRESS`, `DEMO_LOGINS_ENABLED=false`, `STAFF_REQUIRE_2FA=true`, `CONTACT_INBOX`.
2. **Schema + reference data** — `php artisan migrate --force` then `php artisan db:seed --force` (in production this runs **only** `ReferenceDataSeeder`: roles/permissions, plans, tax rules, document types, species, pages, glossary — no users, no demo data). Never run `DemoDataSeeder` (it refuses in production).
3. **First admin** — `php artisan admin:create you@cameroontimberhub.com --name="Your Name"` → emails a password-reset link (use `--show-password` only if mail is not working yet, and change it at once). Lowercases the email; promotes an existing user instead of duplicating.
4. **Second admin** — repeat for a second person. Two-person controls (payment credentials, AI key, approvals) cannot be completed by one admin.
5. **2FA enrolment** — both admins sign in, enrol TOTP 2FA, store recovery codes offline.
6. **Files** — `php artisan storage:link`; `php artisan certificates:generate-signing-key` (once; **back up the key file** — losing it invalidates certificate verification).
7. **Workers** — install `deploy/systemd/timberhub-queue.service` (enable --now) and `deploy/cron` (scheduler + backup). Wait 1 min, then `curl -s https://www.cameroontimberhub.com/up/health` must show every check `true`.
8. **Caches** — `php artisan config:cache route:cache view:cache event:cache && php artisan filament:cache-components`.
9. **Backups** — run `deploy/backup.sh` by hand once; set `BACKUP_RCLONE_REMOTE`; do the restore drill in §4 and record it in the table.
10. **Gate** — `php artisan launch:check` must exit 0. It fails on: debug on, log/array mailer, placeholder from-address, non-https `APP_URL`, insecure session cookie, sync queue, demo logins (config, Pennant row, or the demo admin account), unseeded roles/plans, no super_admin, missing storage link or signing key. It warns on: one admin only, scheduler heartbeat stale/never seen, old pending jobs, no payment gateway configured.
11. **DNS / TLS** — A/AAAA records for apex + `www`, valid certificate (auto-renew), http→https redirect in nginx; confirm `Strict-Transport-Security` appears on an https response (it only does once `TRUSTED_PROXIES` is right behind a TLS-terminating proxy).
12. **Mail deliverability** — SPF (`v=spf1 include:<provider> -all`), DKIM (provider key published), DMARC (`v=DMARC1; p=quarantine; rua=mailto:…`). Send a password reset to a Gmail and an Outlook address and check headers show `spf=pass dkim=pass dmarc=pass`.
13. **Payments** — enter live gateway credentials in `/admin` (two-person). Mobile-money callbacks are re-verified against the provider's status API (Orange `transactionstatus`, MTN `requesttopay/{ref}`), so a forged callback cannot complete a payment. Do one real low-value payment per gateway.
14. **Monitoring** — uptime monitor on `https://www.cameroontimberhub.com/up/health` (alert on non-200, 1-min interval); the daily `ops:error-digest` reaches `MAIL_OPS_ADDRESS`.

---

## 6. Rate limits (as configured in `AppServiceProvider::registerRateLimiters()`)

| Limiter | Applies to | Limit |
|---|---|---|
| `api-login` | `POST /api/v1/auth/login` | 5/min/IP + 5/min/email |
| `api-register` | `POST /api/v1/auth/register` | strict per-IP |
| `api-rfq` | `POST /api/v1/rfqs` | per-user |
| `api-rfq-verify` | RFQ resend-verification | own budget |
| `api-decision` | quote accept/decline, dispute, milestone confirm | 10/min + 120/hr per user |
| `api-key` | whole `/api/v1` group | plan-tier: basic 30 / standard 60 / elevated 300 per min; unauth 60/min/IP |
| `rfq-submit`, `rfq-step` | web RFQ wizard | per-IP |
| `receipt-verify`, `certificate-verify`, `checkpoint-track` | public verification pages | 10/min/IP |
| `inquiry-submit` | company inquiry form | 8/hr/IP |
| `app-notify` | mobile-app launch notify | tiny burst |
| `demo-login` | one-click demo personas | 6/min/IP |
| `message-send`, `message-start` | in-thread messaging | 20/min, 30/hr |
| `order-upload`, `order-review`, `chat-*` | order docs / chat commerce | 5–12 per window |
| `csp-report` | `POST /csp-report` | 60/min/IP |
| `session-write` | locale switch, RFQ shortlist add/remove | 60/min/IP |

---

## 7. Escalation — blocked work

Items that cannot ship without an owner/legal decision (see `docs/GAP_PLAN.md` and `docs/superpowers/plans/2026-09-10-production-readiness.md`):

| Item | Who decides |
|---|---|
| Error-tracking sink (Sentry vs. self-hosted) | owner — until then the `errors` channel + daily digest is the mechanism |
| Offsite backup target | owner + hosting |
| Payment provider (billing engine) | owner — manual admin plan assignment for now |
| Forest Sponsorship (offer it at all?) | COSUMAF / CEMAC counsel |
| PEFC API access | someone must apply at pefc.org |
| Live GPS tracking (Traccar infra + devices) | owner + logistics partner |
| Price-index data product publication | CEMAC competition-law review + published methodology |
