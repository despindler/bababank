# Ba Ba Bank

Ba Ba Bank is a small German-language web application for tracking family bank accounts. It lets children see their account balance, transaction history, and reward chests, while a boss/admin user can manage customer accounts, record deposits and payouts, review bank-wide metrics, and tune reward settings.

The application has been live for several years. This repository contains the legacy web app plus a dump of the live database under `database/` so future refresh work can be grounded in the current production data model.

## Current Architecture

The app is a plain PHP and plain JavaScript site:

- `site/` is the web root.
- `site/index.html` is the public login page.
- `site/customer/` is the customer dashboard.
- `site/boss/` is the boss/admin interface.
- `site/app.js` is the shared frontend API and UI script.
- `site/styles.css` is the shared visual system.
- `site/backend/backend.php` defines the JSON API routes.
- `site/backend/database.php` contains the PDO-based MySQL/MariaDB access functions.
- `site/backend/monthly_interest.php` contains deterministic monthly-interest money, period, rate, and projection rules.
- `site/backend/monthly_interest_processor.php` atomically settles every due customer/month and creates linked transactions and reward chests.
- `site/backend/google_auth.php` verifies Google Identity Services ID tokens server-side.
- `site/backend/rewards.php` contains the configurable reward and interest logic.
- `site/backend/flight/` is a vendored copy of the Flight PHP micro-framework.
- `site/assets/rewards/` contains the customer reward chest images used by the dashboard modal.
- `site/router.php` is a local development router for the PHP built-in server.
- `site/config.php` loads database configuration from environment variables or `.env` files.
- `database/e93ud_bank.sql` is the live database dump.
- `database/schema.sql` is the latest schema for fresh database creation.
- `database/seed.sql` seeds a fresh database with live customers and transactions, excluding legacy leases.
- `database/migrations/` contains live incremental migration scripts.
- `.env.example` documents the required local configuration values.

The frontend uses CDN-hosted Bootstrap 5.3.8, Bootstrap Icons 1.13.1, Bootstrap JS, and plain JavaScript. There is no build step and no jQuery dependency.

The browser calls API paths such as `/backend/auth/login`. In local development, `site/router.php` routes `/backend/*` requests to `site/backend/backend.php`. In production or another web server, configure the same rewrite behavior.

## Product Model

There are two main user flows.

Customer flow:

1. A customer logs in from the root page.
2. The backend verifies username/password or a Google ID token.
3. A server-side PHP session is created and tracked with an HttpOnly cookie.
4. The customer dashboard shows:
   - current balance
   - `Voraussichtlicher Monatszins`, calculated by the server from the current balance with the next first-of-month posting date
   - a piggy-bank count based on one pig per 100 balance units
   - number of incoming and outgoing approved transactions
   - transaction history
   - daily reward chests for new deposits, savings milestones, input/withdrawal achievements, and monthly interest
5. The customer can open a Twint/WhatsApp payment request link.

Boss/admin flow:

1. A boss user opens `/boss/`.
2. Boss login requires an account whose `boss` value is high enough.
3. The boss interface has top-level views:
   - Banking: bank-wide metrics, customer balances, deposits, payouts, and the transaction accordion
   - Users: create users, edit names/usernames/passwords/Google email addresses, archive users, and restore archived users
   - Rewards: edit reward settings and review unopened reward chests per user

## Database

The dump in `database/e93ud_bank.sql` targets MariaDB/MySQL and was generated from MariaDB 10.6.

Tables:

- `customers`
  - account and login records
  - important columns: `id`, `fullname`, `username`, `userpassword`, `google_sub`, `email`, `display_name`, `boss`, `realm`, `deleted_at`
  - `username` is unique
  - `google_sub` and `email` are unique after applying the Google identity migration
- `leases`
  - legacy login/session lease tokens
  - important columns: `id`, `customer`, `datetime`, `lease`, `valid`
  - linked to `customers.id`
- `transactions`
  - account movements
  - important columns: `id`, `customer`, `datetime`, `amount`, `balance`, `kind`, `note`, `approved`, `undone`
  - linked to `customers.id`
- `customer_reward_state`
  - generic per-customer reward state, such as current savings level, input-lead state, and daily chest display date
- `reward_events`
  - auditable reward queue rows shown as customer chests
  - links interest rewards to the transaction that created money
- `reward_config`
  - editable reward configuration, including rates, threshold steps, and enable/disable switches
- `monthly_interest_rates`
  - effective-dated global monthly-interest rates, keyed by the first day of the interest period
- `customer_interest_eligibility`
  - per-customer accrual intervals; the end date is exclusive so archived months cannot be back-paid after restoration
- `monthly_interest_postings`
  - one auditable settlement per customer and calendar month, with the balance basis, applied rate, amount, effective instant, and optional transaction/reward links

The schema has foreign keys from `leases.customer` and `transactions.customer` to `customers.id`, both with cascade delete/update.

There are two supported database paths, and they must stay in sync:

- Fresh rebuild:
  - run `database/schema.sql`
  - run `database/seed.sql`
- Live/incremental update:
  - run the migration scripts in `database/migrations/` in filename order

If future work changes persisted state or schema, add an explicit SQL migration script and update `database/schema.sql` in the same work package. If seed data assumptions change, update `database/seed.sql` too. Do not rely only on editing the live dump.

Current migrations:

- `database/migrations/20260602_001_add_google_identity_to_customers.sql`
  - adds `google_sub`, `email`, and `display_name` to `customers`
  - adds unique indexes for `google_sub` and `email`
- `database/migrations/20260603_001_add_rewards.sql`
  - adds `kind` and `note` to `transactions`
  - creates `customer_reward_state` and `reward_events`
  - initializes current customers to their existing achievement state so live users do not receive retroactive milestone rewards
- `database/migrations/20260603_002_add_boss_management.sql`
  - adds `customers.deleted_at` for soft-deleting users
  - creates and seeds `reward_config`
- `database/migrations/20260724_001_add_monthly_interest_postings.sql`
  - is the single production migration for the monthly-interest update
  - changes stored money columns to fixed two-decimal values
  - creates monthly-interest rate history, customer eligibility intervals, and the posting ledger
  - explicitly sets August 2026 as the first accrual period, with the first posting due on September 1, 2026
  - initializes the August rate from the existing global reward configuration
  - initializes all current active, non-boss customers and removes the obsolete login-triggered period state
  - is validated by `npm run test:migration` against both a synthetic pre-change fixture and a disposable restore of the current `database/e93ud_bank.sql` live snapshot

`database/seed.sql` intentionally omits all rows from `leases`; sessions are runtime state and should not be restored from the historical live dump. It preserves live customers, password hashes, transaction history, balances, `approved`, and `undone` flags.

`database/test-seed.sql` is the sanitized test seed. It contains required configuration and the August 2026 global rate, but no customer identities, credentials, transactions, or sessions.

## Backend API

All API routes are defined in `site/backend/backend.php`.

Read routes:

- `GET /backend/transactions`
  - boss-only; returns all non-undone transactions with customer names
- `GET /backend/customers/me/transactions`
  - returns non-undone transactions for the logged-in customer
- `GET /backend/customers/{id}/transactions`
  - returns non-undone transactions for one customer; non-boss users may only request their own ID
- `GET /backend/customers/me/kpis`
  - returns balance, pig count, incoming count, outgoing count, and the server-calculated monthly-interest projection for the logged-in customer
- `GET /backend/customers/{id}/kpis`
  - returns balance, pig count, incoming count, outgoing count, and monthly-interest projection; non-boss users may only request their own ID
- `GET /backend/customers`
  - boss-only; returns non-boss customers in the boss user's realm
- `GET /backend/boss/overview`
  - boss-only; returns bank-wide metrics and per-customer balances for the boss user's realm
- `GET /backend/boss/rewards`
  - boss-only; returns reward configuration, unopened reward counts per user, and recent reward events
- `GET /backend/customers/{realm}`
  - boss-only compatibility route; ignores the client-supplied realm and uses the boss user's session realm
- `GET /backend/customers/me/rewards/daily`
  - returns unopened rewards for the logged-in customer
  - unopened reward events are not suppressed by an earlier same-day empty check, so deposits added later still appear on the next customer login

Write/auth routes:

- `POST /backend/auth/login`
  - authenticates a customer or boss and creates a PHP session
  - expects `username`, `userpassword`, optional `boss`
- `GET /backend/auth/config`
  - returns public auth configuration, including `google_client_id` when Google sign-in is enabled
- `POST /backend/auth/google`
  - verifies a Google Identity Services ID token server-side and creates a PHP session
  - expects `credential`
  - only signs in customers whose verified Google email matches an existing `customers.email`; unknown Google users are rejected
- `GET /backend/auth/me`
  - returns the current session identity, if signed in
- `POST /backend/auth/logout`
  - destroys the current PHP session
- `POST /backend/customers`
  - boss-only; creates a customer in the boss user's realm
  - expects `fullname`, `username`, `userpassword`
- `PUT /backend/customers/{id}`
  - boss-only; updates a customer in the boss user's realm
  - expects `fullname`, `username`, optional `email`, `display_name`, `userpassword`
- `POST /backend/customers/{id}/archive`
  - boss-only; soft-deletes a customer by setting `customers.deleted_at`
  - expects `confirm` to exactly match the customer's current full name
- `POST /backend/customers/{id}/restore`
  - boss-only; restores an archived customer
- `PUT /backend/boss/rewards/config`
  - boss-only; updates editable reward configuration values
- `POST /backend/customers/authenticate`
  - compatibility route for `POST /backend/auth/login`
  - expects `username`, `userpassword`, optional `boss`
- `POST /backend/customers/{id}/lease`
  - compatibility route; checks the current PHP session instead of a lease token
- `POST /backend/customers/{id}/lease/devalidate`
  - compatibility route for `POST /backend/auth/logout`
- `POST /backend/customers/{id}/cashin`
  - boss-only; creates a transaction using a positive or negative `value`
  - positive deposits create a gold chest reward and can trigger achievement interest rewards
  - expects `value`
- `POST /backend/customers/{id}/cashout`
  - boss-only; currently calls the same backend logic as `cashin`
  - expects `value`
- `DELETE /backend/transactions/{id}`
  - boss-only; soft-deletes a transaction
- `POST /backend/customers/me/rewards/{id}/open`
  - marks a reward chest as opened for the logged-in customer

## Running Locally

There is no frontend build step. The app expects PHP with PDO MySQL support, a MariaDB/MySQL database, Node/npm for Playwright tests, and web-server routing for `/backend/*` to `site/backend/backend.php`. Google sign-in additionally requires the PHP OpenSSL extension.

Configuration is loaded by `site/config.php` from environment variables. For local development, copy `.env.example` to `.env` and edit the database values:

```powershell
Copy-Item .env.example .env
```

Required variables:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

`site/config.php` looks for `.env` next to `site/config.php` first, then one directory above it. This supports both local development, where `.env` usually lives in the repository root, and production hosting where the deployed web root is the contents of `site/` and `.env` must sit beside `config.php`.

Optional variables:

- `APP_ENV` or `BABABANK_ENV`
  - when set to `test`, `site/config.php` also loads `.env.test`
- `BABABANK_ENV_FILE`
  - explicit path to an env file, such as `.env.test`
- `SESSION_NAME`
  - PHP session cookie name; defaults to `bababank_session`
- `SESSION_SAMESITE`
  - session cookie SameSite value; defaults to `Lax`
- `SESSION_SECURE`
  - set to `true` in production/HTTPS deployments; defaults to true for production or HTTPS requests
- `GOOGLE_CLIENT_ID`
  - Google OAuth web client ID; when empty, Google sign-in is disabled
- `GOOGLE_JWKS_URL`
  - Google public key endpoint; defaults to `https://www.googleapis.com/oauth2/v3/certs`
- `MONTHLY_INTEREST_RATE`
  - fallback monthly interest rate as a decimal multiplier; `0.0008` means 0.08%
- `SAVINGS_MILESTONE_REWARD_RATE`
  - one-time interest rate for crossing 100, 200, 300, ... from below
- `INPUT_LEAD_REWARD_RATE`
  - one-time interest rate for inbound manual transactions becoming greater than outbound manual transactions
- `REWARD_DEPOSIT_ENABLED`, `REWARD_MONTHLY_INTEREST_ENABLED`, `REWARD_SAVINGS_MILESTONE_ENABLED`, `REWARD_INPUT_LEAD_ENABLED`
  - enable or disable individual reward families

These reward environment variables are fallback defaults. Once `reward_config` exists, boss-managed reward settings are read from the database first.

Monthly-interest rate changes take effect in the next calendar month. Disabling monthly interest schedules a zero rate for the next month, so disabled months are settled without money or chests and are not back-paid after re-enabling.

Google sign-in requires the production PHP runtime to be able to make outbound HTTPS requests to the Google public key endpoint. The verifier first tries `file_get_contents()` when `allow_url_fopen` is enabled, then falls back to cURL when the PHP cURL extension is available.

Typical local setup:

1. Create a local MariaDB/MySQL database.
2. Import `database/schema.sql`.
3. Import `database/seed.sql`.
4. Copy `.env.example` to `.env` and set the local database credentials.
5. Serve the `site/` directory with PHP and the local router:

```powershell
php -S localhost:8000 -t site site/router.php
```

Then open:

- `http://localhost:8000/` for customer login
- `http://localhost:8000/boss/` for boss/admin login

## Monthly Interest Processing

Run the idempotent posting processor from the repository root:

```powershell
npm run interest:process
```

Schedule this command at least hourly. It settles every eligible closed month oldest first, using `Europe/Zurich` itself rather than relying on the scheduler timezone. Each customer is locked and processed transactionally. A non-zero settlement creates one `monthly_interest` transaction and one period-specific reward chest; zero or negative balance periods create only the auditable posting row.

Preview due work without changing persistent state:

```powershell
npm run interest:process -- --dry-run
```

For controlled recovery, limit the same command to one customer:

```powershell
php tools/post-monthly-interest.php --customer=123
```

Customer KPI and reward reads never post interest. There is no HTTP maintenance endpoint. The command emits structured JSON totals, continues after a customer-specific rollback, exits non-zero when periods remain failed, and suppresses overlapping invocations with a database advisory lock. See [Monthly Interest Operations](docs/monthly-interest-operations.md) for the scheduler contract, exit codes, dry-run behavior, and recovery runbook.

## Browser Testing

Playwright is configured for frontend smoke testing against the PHP app. The tests use `.env.test`, create temporary customer and boss users in a disposable test realm, log in through the UI, capture screenshots, and clean up the temporary rows.

Reset the configured test database before database or browser tests:

```powershell
npm run db:test:reset
```

The reset command loads `.env.test`, creates the configured database when needed, and refuses database names that are not clearly test-scoped. It applies `database/schema.sql` and the sanitized `database/test-seed.sql`.

Run the pure monthly-interest rules and real-database schema checks:

```powershell
npm run test:unit
npm run test:db
npm run test:migration
```

Install dependencies and Chromium when needed:

```powershell
npm install
npx playwright install chromium
```

Run the tests:

```powershell
npm test
```

Run functional smoke tests or visual comparisons separately:

```powershell
npm run test:smoke
npm run test:visual
```

The committed visual baselines cover the interest card in positive, zero, and disabled states plus an opened monthly-interest chest on desktop Chromium and mobile Chrome. Update them intentionally with `npx playwright test --grep @visual --update-snapshots` after reviewing the rendered UI.

The Playwright config starts the PHP built-in server through `tools/php-dev-server.js` when nothing is already listening on `http://127.0.0.1:8787/`. You can also start it manually:

```powershell
npm run dev:test
```

Generated screenshots, traces, and reports are written under ignored test artifact directories.

## Refresh Notes

Important issues to address during modernization:

- Google sign-in links only existing customers by verified email. Bosses can now set that email address in the Users view.
- Balances are stored on each transaction and also recalculated from transaction sums in places. This should be reviewed before changing transaction behavior.
- The legacy `leases` table is still present for historical compatibility, but active authentication now uses PHP sessions.
- The frontend still depends on CDN assets. Consider vendoring assets or adding an asset pipeline if offline/deploy reproducibility becomes important.
- Reward chest images are currently copied from `misc/chests` without an image optimization step. Optimizing those PNGs would reduce first-load weight.
- The current UI has been consolidated into shared Bootstrap/plain-JS screens. The next refresh work should focus on deeper banking workflows and finer reward design.

## Verification Status

Recent checks were run against `.env.test` with the PHP built-in server.

- `php -l` passed for the main PHP entry points.
- `node --check site/app.js` passed.
- HTTP smoke checks returned 200 for `/`, `/customer/`, `/boss/`, `/styles.css`, and `/app.js`.
- Password login, session cookies, customer KPI/transaction APIs, boss login, boss customer listing, and boss transaction listing were verified with temporary test users and then cleaned up.
- `npm run test:unit` passed 19 deterministic monthly-interest domain tests.
- `npm run test:db` passed 22 real-MySQL checks, including schema constraints, projection states, exact cutoff balance selection, three-month compounding, per-period rates and chests, zero settlements, archive gaps, rollback, concurrent processing, idempotency, dry-run, scheduler boundaries, customer failure continuation, structured CLI failures, advisory locking, and protected system transactions.
- `npm run test:migration` passed the single monthly-interest migration against both a synthetic pre-change fixture and a disposable restore of the current live dump, including cent-preserving conversion, explicit August 2026 cutover, rate and eligibility initialization, and legacy-state removal.
- `npm run test:smoke` passed 58 functional Playwright checks across desktop Chromium and mobile Chrome, including authenticated projections, historical-rate catch-up, disabled settlement, idempotency, three chronological chests, and refreshed balances/transactions.
- `npm run test:visual` passed 8 committed component comparisons twice consecutively across desktop Chromium and mobile Chrome.
- A manual Playwright visual pass verified the boss Banking, Users, and Rewards views on desktop and the Rewards view on mobile.
- Google token verification still requires a real Google ID token and PHP OpenSSL. The server-side Google auth path is present, but end-to-end Google sign-in should be rechecked in a browser after configuring a valid Google client.
