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
  sudo -u timberhub php artisan db:seed --class=RolesAndPermissionsSeeder --force && \
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

- **Run `deploy/backup.sh` before `migrate --force` — required, not optional.** Most migrations are additive, but this release ships **irreversible data migrations**: `2026_10_01_100100_normalise_user_emails_to_lowercase` (rewrites `users.email`), `2026_10_01_130000_backfill_email_verified_at_for_existing_users` (sets `email_verified_at`), `2026_10_01_140000_enable_leads_receive_on_free_plan` (flips the Free plan's `leads_receive`). Their `down()` cannot restore the previous values — only the backup can.
- **PostgreSQL extensions (superuser, once, before the first migrate of this release):** the app DB user usually cannot create extensions, so the search-index migration silently falls back to accent-sensitive `lower()` matching. As `postgres`: `sudo -u postgres psql -d cameroontimberhub -c 'CREATE EXTENSION IF NOT EXISTS unaccent; CREATE EXTENSION IF NOT EXISTS pg_trgm;'`. `launch:check` warns if either extension or `ct_unaccent()` is missing.
- `db:seed --class=RolesAndPermissionsSeeder --force` must run on every deploy: staff permissions live in the database, so new permissions/role grants in the seeder only take effect once it is re-run. It is idempotent (`findOrCreate` + `syncPermissions`) and does not touch user role assignments — but it resets each staff role's permissions to the seeder matrix, so manual permission edits on those roles in prod are overwritten.
- `queue:restart` + a `timberhub-queue.service` restart are both needed: the signal tells running workers to finish and exit, the service restart brings them back with the new code.
- After deploy: `GET /up/health` should be `ok`; `php artisan about --only=cache` should show every cache `CACHED`.

## 3. Rollback

```bash
ssh -i ~/.ssh/cameroontimberhub_deploy root@www.cameroontimberhub.com \
 'cd /home/timberhub/htdocs/www.cameroontimberhub.com && \
  sudo -u timberhub git reset --hard <last-good-sha> && \
  sudo -u timberhub composer install --no-dev --optimize-autoloader && \
  sudo -u timberhub php artisan config:cache route:cache view:cache event:cache && \
  sudo -u timberhub php artisan db:seed --class=RolesAndPermissionsSeeder --force && \
  sudo -u timberhub php artisan filament:cache-components && \
  sudo -u timberhub php artisan scramble:clear && \
  sudo -u timberhub php artisan queue:restart && \
  systemctl reload php8.3-fpm && systemctl restart timberhub-queue.service'
```

(The roles seeder re-syncs staff permissions to the rolled-back code's matrix; `scramble:clear` drops the newer code's cached API docs.)

A migration that ran and now needs reverting: `php artisan migrate:rollback --step=1 --force` **only if** its `down()` is safe. Prefer rolling forward with a fix. Release-specific notes:

- **Data migrations `100100` (email lowercase), `130000` (email_verified backfill) and `140000` (Free plan leads) cannot be undone by `down()`** — restore from the pre-deploy backup (§4) if the old values are needed.
- **`2026_10_01_110000_allow_transport_rfq_type`**: before rolling it back, every RFQ with `type = transport` must be deleted or converted to another type, otherwise the restored constraint fails.
- **Do not roll back `2026_10_01_100000_make_api_key_metas_company_nullable_for_agent_keys`**: agent (Hermes) keys have no company, so re-adding `NOT NULL` fails or forces deleting those keys. Leave it in place even when rolling back the code.

---

## 4. Backups & restore

**Backup:** `deploy/backup.sh` (installed from `deploy/cron`, daily 02:00) writes `cth-db-<stamp>.dump` (`pg_dump -Fc`) **and** `cth-files-<stamp>.tar.gz` (`storage/app` — uploaded documents, certificates, media; excludes framework caches/logs/livewire-tmp) to `/home/timberhub/backups`, keeps `BACKUP_RETENTION_DAYS` (default 14) days, and copies both offsite with `rclone` when `BACKUP_RCLONE_REMOTE` is set. DB-only backups lose every uploaded document — always restore both. The **certificate signing key** is inside the files archive when it lives at the default `storage/app/certificates/signing-key.json`; when `CERTIFICATE_SIGNING_KEY_PATH` points elsewhere (e.g. `/home/timberhub/secrets/`), the script copies it to `cth-signing-key-<stamp>.json` (mode 600) next to the archives and offsite. Keep a second copy in the password manager too.

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

Cron (as `timberhub`, file: `deploy/cron`): `* * * * * cd /home/timberhub/htdocs/www.cameroontimberhub.com && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1` (cron and the systemd unit call the versioned `/usr/bin/php8.3`, so a second PHP install or `update-alternatives --set php …` cannot silently switch versions) — **this must exist** or the outbox relay, digests, badge expiry and queue-health all stop silently. Verify: `crontab -l -u timberhub`; `/up/health` reports `checks.scheduler: false` (503) when the every-minute `ops:scheduler-heartbeat` is > 3 min stale.

Queue worker (unit file: `deploy/systemd/timberhub-queue.service`): `systemctl status timberhub-queue.service` — must be `active (running)`. Logs: `journalctl -u timberhub-queue -n 100`.

### RFQ "open request" distribution (config `timber.rfq.*`)

- **Auto-approve** (`RFQ_AUTO_APPROVE_LOW_RISK`, default `true`): when a buyer confirms their email and `RfqRiskService` raised no flags (spam_score 0), the RFQ is approved without staff. Flagged RFQs stay `new` for triage.
- **Auto-route on approval** (`RFQ_AUTO_ROUTE_ON_APPROVAL`, default `true`): every approval (staff or auto) routes the RFQ to all matching suppliers from `RfqMatchingService` (verified, handle the species, RFQ-type targeting, plan `leads_receive` on), at most `RFQ_AUTO_ROUTE_MAX` (default 30). Suppliers get `RfqRoutedToExporter`, the buyer gets the "sent to N suppliers" email. Staff can still route extra companies from Admin → RFQs (already-routed companies are skipped). Failures are logged to the `errors` channel and never undo the approval.
- **Buyer requests board**: suppliers also see matching approved RFQs not routed to them (exporter panel → Buyer requests; API `GET /api/v1/supplier/rfq-board`) without buyer contact details; quoting self-routes them.
- **Who receives leads** is controlled only by the plan's "Receive RFQ leads" toggle in Admin → Plans (Free plan: on at launch). Turning it off stops routing and empties that plan's board. To pause all automatic distribution set `RFQ_AUTO_ROUTE_ON_APPROVAL=false` and/or `RFQ_AUTO_APPROVE_LOW_RISK=false`, then `config:cache`.

### Marketplace commission (PRICING_SPEC §15)

- **Where the rates live:** Admin → Commission rules (`pricing.manage`, e.g. finance officer). Seeded by `CommissionRuleSeeder` (part of `ReferenceDataSeeder`) and, on an existing DB, by the data migration `2026_10_01_170010_seed_default_commission_rules` — both insert only when no rule exists for that segment + plan tier, so they never overwrite your edits. Seeded: sell/`free` 3% dom / 5% intl (cap 5%), sell/`professional` and export/`exporter-professional` 2.5% / 4% (cap 4%), export/`exporter-business` 2% / 3% (cap 3%), all with a fixed cap of 5,000 **USD**. Enterprise tiers (`enterprise`, `exporter-enterprise`) and the `deal` segment have **no rule → 0%** until you add one.
- **Changing a rate:** an active rule's rate/cap cannot be edited in place (it may already be on orders). Create a NEW rule for the same segment + tier with the new rates and `effective_from` = the change date, mark it active, then set `effective_until` on the old one (or leave it — the later `effective_from` wins). Rates are entered as percentages (2.5 = 2.5%). Past orders keep their snapshot. To stop charging a tier, untick *Active* — **don't delete** a seeded rule (a later seeder run would re-create it).
- **Enterprise / negotiated rate:** add a rule with segment + plan tier = the enterprise slug and the contract rates.
- **Cap currency:** the §15 "$5,000" cap is stored as cap amount 5000 / cap currency USD. For XAF orders it is converted at `COMMISSION_USD_TO_XAF` (default 600 → 3,000,000 XAF); EUR uses the XAF/EUR peg (or `COMMISSION_USD_TO_EUR`); GBP/CNY only if `COMMISSION_USD_TO_GBP`/`_CNY` are set — otherwise only the percent cap applies to those orders. Change the env value, then `config:cache`. A blank cap currency means "the order's own currency".
- **When it is charged:** on every order created from an accepted quote (no Trade Assurance requirement — owner decision), once, when the supplier confirms the order (awarded → confirmed); the rate/amount are snapshotted on the order (supplier pays; the buyer's quote screen shows it as supplier-paid). Cancelled before the supplier confirms → never charged; cancelled after confirmation (only possible before shipping) → credited back in full automatically. A dispute decision can credit part/all of it: Admin → Disputes → Resolve → "Credit back marketplace commission". Every credit is in the `commission_credit` activity log. If a charge fails it is logged (`Order confirmed but marketplace commission could not be charged`) and the confirmation still stands; `CommissionCalculator::charge($order)` can be re-run safely (idempotent).
- **Reporting:** Admin → Commission report — per currency and segment (never summed across currencies); GMV excludes cancelled orders. Commission is recorded/accrued on the order only — invoicing/collecting it from suppliers is not automated yet.

### Payment provider fees (config `payments.<provider>.fee_*`)

- **What it does**: every plan checkout prices the charge through `ProviderFees::breakdown()`. With `fee_bearer=buyer` (PayPal default) the payer total is grossed up so the platform nets the list price — `total = (price + fixed) / (1 − percent/100)`, rounded **up** (XAF 0dp, others 2dp) — and the checkout page / `GET /api/v1/billing/checkout/{plan}` show *Subtotal · PayPal processing fee · Total* before the payer authorises. With `fee_bearer=platform` (Stripe, MTN MoMo, Orange Money defaults) the payer pays the list price and the fee is recorded as a platform cost.
- **Defaults** (env, `.env.example`): PayPal `4.4% + 0.30 USD`, buyer; Stripe `3.4% + 0.30`, platform; MTN MoMo / Orange Money `0`, platform. `PAYPAL_FEE_FIXED` is in `PAYPAL_CURRENCY` (USD) — a charge in another currency gets the percentage only (logged warning). **Confirm the PayPal rate on the live merchant account** (it varies by country/volume) before launch.
- **Change without a deploy**: `/admin` → Payment settings → *Edit fees* on the provider row (`payments.manage`; activity-logged with before/after). Blank fields fall back to env. A change affects new checkouts only — each `payments` row snapshots `base_amount`, `provider_fee_amount`, `provider_fee_bearer` at creation and `amount` (what PayPal is asked to capture, and what the capture is verified against) never changes afterwards.
- **Where it shows**: the invoice for a subscription payment has the fee as its own untaxed line (subtotal + tax + fee = amount charged); `/billing` payment history and the success page show it; `/admin` → Commission report → *Payment provider fees* lists fees collected (passed through) vs absorbed per provider and currency, completed payments only.
- **Revenue-share maths** (e.g. referral commission) must use `Payment::baseAmount()` / `subtotalAmount()`, never `amount`, so the payer's fee is not shared out.

### Referral commission payouts (PayPal)

Referrers earn 10% (Admin → Referral settings) of a referred company's first subscription payment, computed on the price **excluding tax and any provider fee passed to the buyer** (`ReferralService::commissionBase()`). Payouts live in Admin → Referral earnings (needs `payments.manage`: super_admin, finance_officer).

- **PayPal prerequisites** — the PayPal REST app whose credentials are in Admin → Payment settings must have **Payouts** enabled (PayPal business account → Developer dashboard → the app → Features → Payouts; live accounts need PayPal to approve Payouts first). Keep the business balance funded in the payout currency; `INSUFFICIENT_FUNDS` fails the payout (it can be re-requested).
- **Webhook events** — on the same PayPal webhook (`https://www.cameroontimberhub.com/payments/paypal/webhook`, id in `PAYPAL_WEBHOOK_ID` / Payment settings) also subscribe: `PAYMENT.PAYOUTS-ITEM.SUCCEEDED`, `PAYMENT.PAYOUTS-ITEM.FAILED`, `PAYMENT.PAYOUTS-ITEM.UNCLAIMED`, `PAYMENT.PAYOUTS-ITEM.RETURNED`, `PAYMENT.PAYOUTS-ITEM.BLOCKED`, `PAYMENT.PAYOUTS-ITEM.DENIED`, `PAYMENT.PAYOUTS-ITEM.REFUNDED`, `PAYMENT.PAYOUTS-ITEM.CANCELED`, `PAYMENT.PAYOUTS-ITEM.HELD`, `PAYMENT.PAYOUTSBATCH.DENIED`. Signatures are verified exactly like checkout webhooks. `referrals:refresh-payouts` (hourly, scheduled) is the safety net if a webhook is missed.
- **Currencies** — only `PAYPAL_PAYOUT_CURRENCIES` (default `USD,EUR,GBP`) can go through PayPal. **PayPal cannot pay XAF**: XAF commissions are paid by MoMo / bank and recorded with "Mark paid manually".
- **Flow** — the referrer sets a PayPal email (web `/account/settings` → Referral payouts, or API `PATCH /api/v1/referrals/payout-settings`). Approve the commission → admin A clicks **Request PayPal payout** (single or bulk) → a **different** admin B clicks **Approve payout** (with a fresh 2FA confirmation when `STAFF_REQUIRE_2FA=true`) → PayPal is called. Outcome: *Paid* (earning paid, referrer emailed), *Failed* (referrer emailed, can be re-requested), *Unclaimed* (the email has no PayPal account; PayPal holds it 30 days, then it comes back as RETURNED → Failed).
- **Never pay twice** — one live payout per commission is enforced by a DB unique index; the PayPal `sender_batch_id` is `CTH-REFPAY-{payout id}` so a retry is deduplicated by PayPal. If a submission timed out, use **Refresh payout status** (re-submits the same batch id safely). If it shows provider status `DUPLICATE`, check PayPal → Activity for that batch id: if it was paid, use **Mark paid manually** with the PayPal transaction id as the reference.
- **Manual payments** — **Mark paid manually** requires the MoMo / bank reference; it is stored on the payout and in the activity log (`log_name = referral_payout`).
- If PayPal is not configured the PayPal actions are disabled with an explanation; manual marking still works.

---

## 5a. Go-live checklist

Work through in order on the production host. Every step is re-runnable.

1. **Environment** — copy the commented *PRODUCTION* block at the bottom of `.env.example` into `.env` and fill it in: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://www.cameroontimberhub.com`, `APP_KEY` (generate **once**, store in the password manager), `TRUSTED_PROXIES` (loopback for nginx on the same host; the Cloudflare/LB CIDRs or `*` if traffic comes through one), `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=redis`, `MAIL_MAILER=smtp` + real SMTP creds + `MAIL_FROM_ADDRESS` on our domain, `MAIL_OPS_ADDRESS`, `DEMO_LOGINS_ENABLED=false`, `STAFF_REQUIRE_2FA=true`, `CONTACT_INBOX`.
2. **Schema + reference data** — `php artisan migrate --force` then `php artisan db:seed --force` (in production this runs **only** `ReferenceDataSeeder`: roles/permissions, plans, tax rules, document types, species, pages, glossary — no users, no demo data). Never run `DemoDataSeeder` (it refuses in production).
3. **First admin** — `php artisan admin:create you@cameroontimberhub.com --name="Your Name"` → emails a password-reset link (use `--show-password` only if mail is not working yet, and change it at once). Lowercases the email; promotes an existing user instead of duplicating.
4. **Second admin** — repeat for a second person. Two-person controls (payment credentials, AI key, approvals) cannot be completed by one admin.
5. **2FA enrolment** — both admins sign in, enrol TOTP 2FA, store recovery codes offline.
6. **Files** — `php artisan storage:link`; `php artisan certificates:generate-signing-key` (once; **back up the key file** — losing it invalidates certificate verification).
7. **Workers** — `mkdir -p /home/timberhub/backups` (as `timberhub`; the cron backup line logs there), then install `deploy/systemd/timberhub-queue.service` (enable --now) and `deploy/cron` (scheduler + backup). Both use `/usr/bin/php8.3` — check it exists (`ls -l /usr/bin/php8.3`). Wait 1 min, then `curl -s https://www.cameroontimberhub.com/up/health` must show every check `true`.
8. **Caches** — `php artisan config:cache route:cache view:cache event:cache && php artisan filament:cache-components`.
9. **Backups** — run `deploy/backup.sh` by hand once; set `BACKUP_RCLONE_REMOTE`; do the restore drill in §4 and record it in the table.
10. **Gate** — `php artisan launch:check` must exit 0. It fails on: debug on, log/array mailer, placeholder from-address, non-https `APP_URL`, insecure session cookie, sync queue, demo logins (config, Pennant row, or the demo admin account), unseeded roles/plans, no super_admin, missing storage link or signing key. It also fails in production when `STAFF_REQUIRE_2FA` is not true. It warns on: one admin only, any super_admin/admin without confirmed 2FA, blank `TRUSTED_PROXIES` (current value is printed), `SIGNUP_CARBON_ENABLED=true`, blank/placeholder `CONTACT_INBOX`, `MAIL_OPS_ADDRESS` unset (falls back to the from-address), missing `unaccent`/`pg_trgm`/`ct_unaccent()`, `timberhub-queue` not active (only where `systemctl` exists), scheduler heartbeat stale/never seen, old pending jobs, no payment gateway configured. It prints `API_REQUIRE_TERMS_ACCEPTED` for information (keep `false` until every live mobile build sends `terms_accepted`).
11. **DNS / TLS** — A/AAAA records for apex + `www`, valid certificate (auto-renew), http→https redirect in nginx; confirm `Strict-Transport-Security` appears on an https response (it only does once `TRUSTED_PROXIES` is right behind a TLS-terminating proxy).
12. **Mail deliverability** — SPF (`v=spf1 include:<provider> -all`), DKIM (provider key published), DMARC (`v=DMARC1; p=quarantine; rua=mailto:…`). Send a password reset to a Gmail and an Outlook address and check headers show `spf=pass dkim=pass dmarc=pass`.
13. **Payments** — enter live gateway credentials in `/admin` (two-person). Mobile-money callbacks are re-verified against the provider's status API (Orange `transactionstatus`, MTN `requesttopay/{ref}`), so a forged callback cannot complete a payment. Do one real low-value payment per gateway.
14. **Driver accounts** — field checkpoint capture (`/logistics/shipments/{waybill}/checkpoint`) now requires a signed-in user who is a member of the shipment's carrier company or the order's supplier company (`ShipmentService::canRecordCheckpoints()`). Drivers therefore need their own login, added as a user of that company (exporter panel → team) **before** they go on the road; holding a waybill number alone no longer works. Offline captures by a signed-out driver are not accepted.
15. **Email-lowercase migration** — after `migrate`, check the log for `users.email has case-insensitive duplicates; lower(email) unique index NOT created.` (`grep -n "case-insensitive duplicates" storage/logs/laravel*.log`). If present, the listed accounts were left untouched and the unique index is missing: merge/rename the duplicates by hand, then re-run the migration (`migrate:refresh --path=database/migrations/2026_10_01_100100_normalise_user_emails_to_lowercase.php --force`, or create the index manually).
16. **Carbon signup stays off** — keep `SIGNUP_CARBON_ENABLED=false` until an admin CarbonProject review resource exists in `/admin`; without it a carbon project cannot move past Submitted. The public "Carbon Projects" nav/footer links stay hidden while the flag is off and no project is listed (count cached 10 min).
17. **Monitoring** — uptime monitor on `https://www.cameroontimberhub.com/up/health` (alert on non-200, 1-min interval); the daily `ops:error-digest` reaches `MAIL_OPS_ADDRESS`.

### Launch day sequence

The one-pass order for go-live day (details in the checklist above):

1. **Backup** — `deploy/backup.sh` (as `timberhub`); confirm the `cth-db-*`, `cth-files-*` (and `cth-signing-key-*` if applicable) files exist.
2. **Superuser pre-step** — `sudo -u postgres psql -d cameroontimberhub -c 'CREATE EXTENSION IF NOT EXISTS unaccent; CREATE EXTENSION IF NOT EXISTS pg_trgm;'`
3. **Pull** — fast-forward `origin/master` (as in §2).
4. **Dependencies** — `composer install --no-dev --optimize-autoloader`
5. **Migrate** — `php artisan migrate --force` (then check the log for the email-duplicates warning, step 15).
6. **Seed roles** — `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (first install: `php artisan db:seed --force` for all reference data).
7. **Caches** — `config:cache route:cache view:cache event:cache`, `filament:cache-components`, `scramble:clear`.
8. **Restart workers** — `php artisan queue:restart`, `systemctl reload php8.3-fpm`, `systemctl restart timberhub-queue.service`.
9. **Gate** — `php artisan launch:check` must exit 0; read every warning.
10. **Smoke URLs** — `curl -sI https://www.cameroontimberhub.com/` (200 + HSTS), `/login`, `/register`, `/marketplace`, `/cameroon-timber-suppliers`, `/request-quote`, `/admin/login`, `/api/v1/products?per_page=1` (`{"data":[…]}`), `/up/health` (all checks `true`).
11. **Admins** — `php artisan admin:create <first>@cameroontimberhub.com --name="…"`, then the **second** admin; both sign in and enrol TOTP 2FA (recovery codes offline).
12. **Staff roles** — in `/admin` → Users, assign `finance_officer` and `billing_officer` to the people doing payment approval / invoicing.
13. **Admin settings** — configure mail and payment gateway settings in `/admin` (two-person for credentials); send a test password reset.
14. **Hermes agent key** — `php artisan agent:create-key hermes`; hand the printed token to the Hermes operator over a secure channel (shown once).
15. **Monitor** — watch `https://www.cameroontimberhub.com/up/health` (uptime monitor, 1-min interval) and `storage/logs` for the first hours.

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
