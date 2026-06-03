# Ba Ba Bank

Ba Ba Bank is a small German-language web application for tracking family bank accounts. It lets children see their account balance and transaction history, while a boss/admin user can register customers, record deposits and payouts, and soft-delete transaction entries.

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
- `site/backend/google_auth.php` verifies Google Identity Services ID tokens server-side.
- `site/backend/flight/` is a vendored copy of the Flight PHP micro-framework.
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
   - a piggy-bank count based on one pig per 100 balance units
   - number of incoming and outgoing approved transactions
   - transaction history
5. The customer can open a Twint/WhatsApp payment request link.

Boss/admin flow:

1. A boss user opens `/boss/`.
2. Boss login requires an account whose `boss` value is high enough.
3. The boss dashboard can:
   - list customers in the same realm
   - create new customer accounts
   - record deposits
   - record payouts
   - list all transactions
   - soft-delete transactions by setting `transactions.undone = 1`

## Database

The dump in `database/e93ud_bank.sql` targets MariaDB/MySQL and was generated from MariaDB 10.6.

Tables:

- `customers`
  - account and login records
  - important columns: `id`, `fullname`, `username`, `userpassword`, `google_sub`, `email`, `display_name`, `boss`, `realm`
  - `username` is unique
  - `google_sub` and `email` are unique after applying the Google identity migration
- `leases`
  - legacy login/session lease tokens
  - important columns: `id`, `customer`, `datetime`, `lease`, `valid`
  - linked to `customers.id`
- `transactions`
  - account movements
  - important columns: `id`, `customer`, `datetime`, `amount`, `balance`, `approved`, `undone`
  - linked to `customers.id`

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

`database/seed.sql` intentionally omits all rows from `leases`; sessions are runtime state and should not be restored from the historical live dump. It preserves live customers, password hashes, transaction history, balances, `approved`, and `undone` flags.

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
  - returns balance, pig count, incoming count, and outgoing count for the logged-in customer
- `GET /backend/customers/{id}/kpis`
  - returns balance, pig count, incoming count, and outgoing count; non-boss users may only request their own ID
- `GET /backend/customers`
  - boss-only; returns non-boss customers in the boss user's realm
- `GET /backend/customers/{realm}`
  - boss-only compatibility route; ignores the client-supplied realm and uses the boss user's session realm

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
- `POST /backend/customers/authenticate`
  - compatibility route for `POST /backend/auth/login`
  - expects `username`, `userpassword`, optional `boss`
- `POST /backend/customers/{id}/lease`
  - compatibility route; checks the current PHP session instead of a lease token
- `POST /backend/customers/{id}/lease/devalidate`
  - compatibility route for `POST /backend/auth/logout`
- `POST /backend/customers/{id}/cashin`
  - boss-only; creates a transaction using a positive or negative `value`
  - expects `value`
- `POST /backend/customers/{id}/cashout`
  - boss-only; currently calls the same backend logic as `cashin`
  - expects `value`
- `DELETE /backend/transactions/{id}`
  - boss-only; soft-deletes a transaction

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

## Browser Testing

Playwright is configured for frontend smoke testing against the PHP app. The tests use `.env.test`, create temporary customer and boss users in a disposable test realm, log in through the UI, capture screenshots, and clean up the temporary rows.

Install dependencies and Chromium when needed:

```powershell
npm install
npx playwright install chromium
```

Run the tests:

```powershell
npm test
```

The Playwright config starts the PHP built-in server through `tools/php-dev-server.js` when nothing is already listening on `http://127.0.0.1:8787/`. You can also start it manually:

```powershell
npm run dev:test
```

Generated screenshots, traces, and reports are written under ignored test artifact directories.

## Refresh Notes

Important issues to address during modernization:

- Google sign-in links only existing customers by verified email; a boss/admin workflow is still needed to set customer email addresses conveniently.
- Balances are stored on each transaction and also recalculated from transaction sums in places. This should be reviewed before changing transaction behavior.
- The legacy `leases` table is still present for historical compatibility, but active authentication now uses PHP sessions.
- The frontend still depends on CDN assets. Consider vendoring assets or adding an asset pipeline if offline/deploy reproducibility becomes important.
- The current UI has been consolidated into shared Bootstrap/plain-JS screens. The next refresh work should focus on functionality and sharper boss workflows.

## Verification Status

Recent checks were run against `.env.test` with the PHP built-in server.

- `php -l` passed for the main PHP entry points.
- `node --check site/app.js` passed.
- HTTP smoke checks returned 200 for `/`, `/customer/`, `/boss/`, `/styles.css`, and `/app.js`.
- Password login, session cookies, customer KPI/transaction APIs, boss login, boss customer listing, and boss transaction listing were verified with temporary test users and then cleaned up.
- `npm test` passed 4 Playwright Chromium smoke tests covering the public login page, signed-out customer page, logged-in customer dashboard, and logged-in boss dashboard.
- Google token verification still requires a real Google ID token and PHP OpenSSL. The server-side Google auth path is present, but end-to-end Google sign-in should be rechecked in a browser after configuring a valid Google client.
