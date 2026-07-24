# Monthly Interest Posting and Customer Projection Plan

Status: In implementation
Last updated: 2026-07-24
Primary guidance: Read `.agents/CODEX.md` before starting or resuming work.

## Progress

- Milestone 0: Completed as supporting work in Milestone 2 on 2026-07-24.
  - Added a guarded `.env.test` database reset, a sanitized configuration-only test seed, and database/migration test commands.
  - Verification: the reset created `bank_test` with 9 tables, 0 customers, and 8 configuration rows; the full existing Playwright suite passed.
- Milestone 1: Completed on 2026-07-24.
  - Added the pure monthly-interest domain model, explicit Zurich period handling, fixed/system clocks, exact cent calculations, historical rate selection, and projection generation.
  - Added a zero-dependency PHP unit runner and `npm run test:unit`.
  - Verification: PHP syntax passed; 19 unit tests passed with `E_ALL`; `git diff --check` passed.
- Milestone 2: Completed on 2026-07-24.
  - Added fixed-decimal money storage, effective-dated global rates, customer eligibility intervals, and a unique customer-period settlement ledger to fresh schema and an ordered migration.
  - Set August 2026 as the explicit first accrual period, with the first posting effective September 1, 2026.
  - Added scheduled-next-period rate updates and archive/restore eligibility handling.
  - Verification: 19 unit tests, 8 fresh-schema database tests, 6 migration tests, and 40 Playwright tests passed against local MySQL.
- Milestone 3: Completed on 2026-07-24.
  - Added the atomic, customer-locked processor and CLI entry point, chronological catch-up with compounding, period-specific transactions/chests, zero settlements, fixed-cent running balances, and protected system transactions.
  - Removed all runtime lazy-posting paths and added the ordered migration that deletes obsolete `monthly_interest_period` state.
  - Verification: 19 unit tests, 16 real-MySQL schema/processor tests, 7 migration tests, and the browser suite passed; concurrent workers produced one payment.
- Milestone 4: Completed on 2026-07-24.
  - Hardened the shared CLI with an hourly scheduler contract, database advisory lock, dry-run simulation, customer-scoped recovery, structured period totals/errors, and non-zero failure exits.
  - Restricted injected `--as-of` values to test environments and documented the production recovery runbook without adding a public maintenance endpoint or changing an external scheduler.
  - Verification: 21 real-MySQL tests cover pre/post month close, retry, three-month dry-run compounding, zero persistent dry-run changes, per-customer failure continuation, non-zero structured CLI failures, production clock guard, and overlapping command suppression.
- Next milestone: Milestone 5 — Customer API and UI Projection.

## 1. Objective

Replace the current lazy, login-triggered monthly-interest behavior with a deterministic month-close system that:

- calculates interest from the customer's balance at the end of each calendar month;
- posts interest automatically on the first day of the following month;
- uses `Europe/Zurich` for all business-period boundaries;
- catches up every missed month in chronological order;
- creates one reward chest for every non-zero monthly-interest payment;
- exposes a server-calculated projection to the customer UI as `Voraussichtlicher Monatszins`;
- remains safe under retries, concurrent requests, partial failures, and scheduler outages.

This is a substantial, multi-component work package. Keep this file current as milestones are completed. Update the status, verification results, decisions, and next milestone after each bounded work package.

## 2. Confirmed Product Decisions

The following decisions govern the implementation:

- Interest is global across all realms/families. There is one global monthly rate schedule.
- An interest period is a calendar month in `Europe/Zurich`.
- The balance basis is the approved, non-undone account balance immediately before local midnight at the start of the next month.
- Interest becomes effective on the first day of the following month.
- Posting is automatic through a scheduled command.
- The same idempotent processor must recover all missed periods after scheduler downtime.
- Recovery must process missed months oldest first so earlier interest participates in later month-end balances.
- Each non-zero monthly payment creates its own transaction, reward event, and chest.
- The customer projection assumes the current balance remains unchanged until the current month closes.
- The customer-facing label is `Voraussichtlicher Monatszins`.
- The customer UI must make clear that the amount is an estimate.

The approved plan also adopts these operational defaults:

- Rate changes take effect from the next open interest period. Closed periods retain their historical rate.
- Disabling monthly interest schedules a zero rate for the next open period; disabled periods settle without money or chests and are not later back-paid.
- Zero-value periods are recorded as settled but do not create a zero-value transaction or chest.
- Posted interest is final. Later changes to old manual transactions do not automatically recalculate closed periods.
- System-created interest transactions cannot be independently deleted.
- Archived customers do not accrue interest while archived and must not receive catch-up for archived periods after restoration.
- The first period handled by the new engine is the first full calendar month after production cutover, avoiding accidental overlap with legacy lazy interest.

If any of these defaults must change, update this file before implementing the affected milestone.

## 3. Current-System Facts and Constraints

- The application is plain PHP, MySQL/MariaDB, HTML, CSS, and JavaScript.
- Before Milestone 3, `site/backend/rewards.php` implemented monthly interest through `rewardsRunLazyMonthlyForCustomer()`.
- The former lazy check ran from customer KPI and daily-reward reads and stored only a `monthly_interest_period` string.
- Milestone 3 removed that path and replaced it with the transactionally locked catch-up processor.
- The posting ledger now enforces customer-period uniqueness in the database.
- The boss-facing rate remains global, while effective historical values are stored in `monthly_interest_rates`.
- Transaction and reward money columns now use fixed two-decimal database values.
- The existing reward modal already displays queued reward events individually; one event per month naturally results in one chest per month.
- There is no scheduled-job implementation in the repository.
- Tests use Playwright and a real MySQL database through `.env.test`.
- At planning time, the configured MySQL server was reachable, but the configured `bank_test` database did not yet exist.
- Composer and the MySQL CLI were not installed. PHP/PDO and Node/npm were available.

Persisted production state matters for this feature even though the repository generally has a prototype clean-slate bias. Every schema change must update:

- `database/schema.sql`;
- a new ordered migration under `database/migrations/`;
- sanitized test seed/setup data;
- `database/seed.sql` if its assumptions or required configuration change;
- `README.md` when setup, testing, configuration, API behavior, scheduler operation, or user behavior changes.

Remove the obsolete lazy monthly-interest implementation when the new processor replaces it. Do not retain two posting engines.

## 4. Target Invariants

These invariants must hold in code and tests:

1. At most one monthly-interest settlement exists for a customer and interest period.
2. A settlement is either fully committed or has no persistent effects.
3. Re-running the processor with the same effective time is safe and produces no duplicates.
4. Concurrent processors produce the same final state as one processor.
5. Every eligible closed period is settled, including periods missed during downtime.
6. Missed periods are processed oldest first.
7. The month-end balance excludes the interest due at that cutoff and includes earlier posted interest.
8. The rate used for a closed period is the rate effective for that period.
9. Negative and zero balances receive zero interest.
10. Currency amounts are rounded once to cents using a documented rule shared by posting and projection.
11. A non-zero settlement links exactly one system transaction and one monthly reward event.
12. A zero settlement is auditable but creates neither a money transaction nor a chest.
13. Archived periods are not later treated as missed eligible periods.
14. The UI projection is read from authenticated server data and does not duplicate financial rules in JavaScript.
15. Business dates are derived in `Europe/Zurich`; database instants are handled consistently in UTC.

## 5. Planned Test Commands

The implementation should converge on these commands:

```powershell
npm run db:test:reset
npm run test:unit
npm run test:db
npm run test:e2e
npm run test:visual
npm test
```

Suggested mapping:

- `db:test:reset`: guarded PHP/PDO database creation and schema/test-seed reset;
- `test:unit`: zero-dependency PHP unit runner for pure interest rules;
- `test:db`: PHP integration runner against `.env.test` and real MySQL;
- `test:e2e`: functional Playwright API/UI smoke tests;
- `test:visual`: Playwright screenshot comparisons;
- `test`: full deterministic verification in the correct order.

Do not make the test suite depend on Google, external APIs, production data, or the wall-clock date.

## 6. Milestone 0 — Deterministic Test Foundation

### Goal

Provide a safe, repeatable local test database and test-command structure before changing financial behavior.

### Deliverables

- Add a PHP/PDO test-database reset command.
- Load `.env.test` explicitly.
- Permit destructive reset only when all safety checks pass:
  - `APP_ENV=test` or equivalent test marker;
  - configured database name is non-empty;
  - configured database name is clearly test-scoped, such as ending in `_test` or starting with `test_`;
  - target is not a system database.
- Connect without initially selecting the database so the command can create the configured test database.
- Apply `database/schema.sql`.
- Add sanitized test seed data containing required global configuration but no live customer identities or password hashes.
- Add npm scripts for the planned test layers.
- Preserve the existing temporary-fixture cleanup discipline.

### Verification

- Reset the configured test database twice.
- Confirm the same tables, indexes, and configuration exist after both runs.
- Confirm no production customer seed data is present.
- Run all existing Playwright suites against the reset database.
- Verify test cleanup leaves no temporary customers, transactions, reward events, or state.

### Exit Criteria

- A new agent can create the test database and run baseline tests using documented commands only.
- The reset command refuses unsafe targets with a clear error.
- Baseline failures, if any, are fixed or explicitly recorded before continuing.

## 7. Milestone 1 — Interest Domain Model and Clock (Completed 2026-07-24)

### Goal

Extract deterministic financial and calendar logic that can be unit tested without HTTP or MySQL.

### Deliverables

- Add a small dedicated monthly-interest domain module rather than extending request handlers.
- Introduce an injectable clock or explicit `as-of` value.
- Centralize the `Europe/Zurich` business timezone.
- Represent an interest period unambiguously, preferably by its first calendar date.
- Implement:
  - current/open period selection;
  - closed-period enumeration;
  - Zurich month cutoff converted to a database-safe instant;
  - posting/effective date calculation;
  - applicable-rate selection;
  - positive-balance interest calculation;
  - cent rounding;
  - customer projection calculation.
- Keep money calculations deterministic. Avoid spreading float arithmetic through the code.
- Add a zero-dependency PHP unit test runner because Composer is not currently available.

### Unit Coverage

- positive balance;
- zero balance;
- negative balance;
- amount below the half-cent threshold;
- exact half-cent boundary;
- ordinary cent rounding;
- January-to-February;
- December-to-January;
- leap-year February;
- Zurich daylight-saving transitions;
- one missed month;
- three missed months in chronological order;
- historical rate selection;
- projected amount and next posting date;
- disabled-interest projection.

### Exit Criteria

- Unit tests are independent of MySQL, HTTP, and the real date.
- Posting and projection call the same amount calculator.
- Date calculations contain no implicit PHP or database server timezone dependency.

## 8. Milestone 2 — Schema, Rate History, Eligibility, and Cutover (Completed 2026-07-24)

### Goal

Create an auditable persistence model with database-enforced uniqueness and explicit migration behavior.

### Deliverables

- Add a `monthly_interest_postings` table or equivalent dedicated ledger containing:
  - customer;
  - interest period;
  - month-end balance basis;
  - applied rate;
  - credited amount;
  - effective posting instant/date;
  - actual processing timestamp;
  - optional linked transaction for non-zero settlements;
  - optional linked reward event for non-zero settlements;
  - a unique key on customer plus interest period.
- Add effective-dated global monthly-interest rate history.
- Update boss rate changes so a new rate is scheduled for the next open period and prior rates remain queryable.
- Add explicit customer-interest eligibility state or intervals sufficient to:
  - establish the cutover/start period;
  - stop accrual when archived;
  - resume without back-paying archived periods.
- Preserve the current global-realm behavior.
- Change relevant monetary columns to fixed decimal types if the pre/post balance audit proves the conversion safe.
- Add an ordered migration.
- Update fresh schema and sanitized test seed.
- Define the production cutover period as an explicit deployment value or migration decision, not an implicit use of the server date.
- Initialize existing active customers so the first eligible new period is the first full month after cutover.
- Remove or supersede legacy `monthly_interest_period` state once no longer used.

### Database Verification

- Apply fresh schema plus sanitized test seed.
- Apply the migration to a database with the pre-change schema and representative data.
- Verify unique customer-period enforcement.
- Verify foreign keys and deletion rules.
- Compare every representative customer balance to the cent before and after any monetary type conversion.
- Verify rate history selects the correct rate across a change.
- Verify archive and restore eligibility intervals do not create archived-period catch-up.
- Verify the cutover initialization cannot duplicate a legacy monthly-interest event.

### Exit Criteria

- Fresh and migrated database paths are both valid and synchronized.
- The database itself prevents duplicate customer-period settlements.
- Cutover behavior is explicit, documented, and covered by a fixture.

## 9. Milestone 3 — Atomic Posting and Catch-Up Processor (Completed 2026-07-24)

### Goal

Implement one reusable processor that settles all due periods correctly and safely.

### Deliverables

- Add a processor callable from CLI and controlled recovery paths.
- For each eligible customer:
  - determine all closed, unposted periods;
  - process periods oldest first;
  - calculate the approved, non-undone balance immediately before the cutoff;
  - select the rate effective for the period;
  - calculate the amount;
  - create a settlement row;
  - for non-zero amounts, create one system transaction and one reward event;
  - make the system transaction effective on the first day of the following month;
  - link all records before committing.
- Use a database transaction and appropriate locking.
- Treat uniqueness conflicts from competing processors as an idempotent already-settled result, not as a second payment.
- Ensure failure anywhere rolls back settlement, transaction, reward event, and balance updates.
- Recalculate running balances deterministically by effective transaction order.
- Use a distinct transaction kind such as `monthly_interest`.
- Use a distinct monthly reward key and period-specific title such as `Monatszins Juli 2026`.
- Prevent generic transaction deletion from independently undoing monthly-interest system transactions.
- Remove the old lazy monthly-interest posting path.

### Real-Database Integration Coverage

- one ordinary month;
- three missed months;
- one chest per non-zero missed month;
- compounding across missed months;
- transaction immediately before cutoff;
- transaction exactly at/after cutoff;
- approved versus unapproved transactions;
- active versus undone transactions;
- negative and zero month-end balances;
- zero settlement audit with no transaction or chest;
- different rates in consecutive missed months;
- forced exception and complete rollback;
- two concurrent processors;
- repeated processor execution;
- archived interval;
- restored customer without archived-period catch-up;
- attempted deletion of a system interest transaction.

### Exit Criteria

- All target invariants are demonstrated against real MySQL.
- Concurrency produces exactly one financial result per customer and period.
- There is no remaining executable path that posts legacy lazy monthly interest.

## 10. Milestone 4 — Automatic CLI Execution and Recovery (Completed 2026-07-24)

### Goal

Provide production-operable automatic posting without exposing a public maintenance endpoint.

### Deliverables

- Add a CLI entry point such as:

```powershell
php tools/post-monthly-interest.php
```

- The command must:
  - process all due customers and periods;
  - be safe to run frequently;
  - print actionable structured totals;
  - distinguish created, zero-settled, already-settled, skipped, and failed periods;
  - exit non-zero when unrecovered failures occur;
  - support a read-only dry-run;
  - support an injected `as-of` only in test mode.
- Document a schedule that runs at least hourly. The processor, not cron timezone configuration, decides whether Zurich month-close has occurred.
- Retain a safe authenticated recovery invocation where useful, but do not make login the primary posting mechanism.
- Recovery must use the exact same processor, never a second implementation.

### Verification

- Run just before Zurich month-close: no posting.
- Run just after month-close: one posting.
- Run again: no new posting.
- Simulate three months of scheduler downtime: one later run settles all three.
- Force one customer failure: command reports it clearly and continues or fails according to the documented policy without corrupting other customers.
- Verify dry-run changes no rows.

### Exit Criteria

- The command is safe under retries and suitable for an external scheduler.
- Logs are sufficient to diagnose missing or failed periods.
- Scheduler operation is documented but no unauthorized production scheduler changes are made from the repository task.

## 11. Milestone 5 — Customer API and UI Projection

### Goal

Show the customer a clear, server-calculated monthly-interest estimate and posting date.

### Deliverables

- Extend the authenticated customer KPI response with a stable structure similar to:

```json
{
  "monthly_interest": {
    "enabled": true,
    "period": "2026-07",
    "balance_basis_estimate": "125.00",
    "rate": "0.00080000",
    "rate_percent": "0.08",
    "estimated_amount": "0.10",
    "posting_date": "2026-08-01",
    "timezone": "Europe/Zurich",
    "is_estimate": true
  }
}
```

- Calculate all financial fields on the server.
- Add a customer dashboard component labeled `Voraussichtlicher Monatszins`.
- Show:
  - estimated amount;
  - monthly percentage;
  - expected first-of-month posting date;
  - concise German copy explaining that the estimate assumes the current balance remains unchanged until month-end.
- Define clear enabled, disabled, zero, and error states.
- Preserve responsive behavior.
- Ensure caught-up reward events open as separate period-labelled chests.
- Keep achievement-interest rewards visually and semantically distinct from monthly interest.

### API and UI Verification

- API projection matches the unit-tested calculator.
- Customer authorization prevents access to another customer's projection.
- JavaScript contains no duplicated rate, cutoff, or rounding rule.
- Positive, zero, negative, and disabled states render correctly.
- Three caught-up monthly rewards appear and open independently in chronological order.
- Balance and transaction views refresh correctly after reward opening.
- Existing customer dashboard behavior remains intact.

### Exit Criteria

- The projection is accurate for the returned balance and rate.
- The UI clearly communicates that the amount is provisional.
- Mobile and desktop layouts are usable and accessible.

## 12. Milestone 6 — Playwright Smoke and Visual Coverage

### Goal

Verify critical customer-visible and end-to-end behavior through the real PHP/MySQL application.

### Deliverables

- Extend Playwright API smoke coverage for:
  - automatic settlement;
  - three-period catch-up;
  - separate monthly chests;
  - idempotent refresh/retry;
  - disabled interest;
  - historical rate changes;
  - correct effective dates and running balances.
- Add customer UI assertions for projection amount, rate, date, explanatory text, and states.
- Add stable `toHaveScreenshot()` visual checks for:
  - desktop positive estimate;
  - mobile positive estimate;
  - zero estimate;
  - disabled interest;
  - monthly chest presentation.
- Use controlled test data and clock values.
- Prefer component/section screenshots over unnecessarily broad full-page baselines.
- Avoid external network dependence where practical. If CDN assets make visual tests unstable, address asset determinism within reasonable scope and document the choice.

### Exit Criteria

- API/database smoke tests pass against `.env.test`.
- Desktop Chromium and mobile Chrome functional tests pass.
- Visual baselines are stable across repeated local runs.
- Failures retain useful traces and screenshots.

## 13. Milestone 7 — Documentation, Cutover, and Release Verification

### Goal

Make the subsystem operable and safe to deploy.

### Deliverables

- Update `README.md` with:
  - monthly-interest business rules;
  - `Europe/Zurich` behavior;
  - customer projection behavior;
  - schema/migration instructions;
  - test-database reset and test commands;
  - scheduled CLI command;
  - required configuration;
  - rate-change semantics;
  - recovery behavior.
- Update `.env.example` for any new timezone, test, cutover, or operational settings.
- Record:
  - chosen production cutover period;
  - legacy lazy-interest state handling;
  - dry-run procedure;
  - backup prerequisite;
  - migration order;
  - scheduler activation order;
  - rollback limitations after financial postings begin;
  - monitoring and recovery commands.
- Run a release rehearsal against:
  - a fresh empty test database;
  - a representative pre-change migrated database.
- Produce a cutover report showing which customers and periods will first become eligible without exposing credentials or unnecessary personal data.

### Final Verification

Run:

```powershell
npm run db:test:reset
npm run test:unit
npm run test:db
npm run test:e2e
npm run test:visual
npm test
```

Also run PHP and JavaScript syntax checks across all changed files.

### Exit Criteria

- Fresh and migration paths pass.
- Full automated verification passes.
- README and configuration examples match executable behavior.
- No obsolete lazy-interest code or dead compatibility path remains.
- Production cutover requires no undocumented manual database edits.

## 14. Implementation Order and Work-Package Discipline

Implement milestones in order. Do not begin the UI before the domain model, persistence, and posting engine are verified.

For each milestone:

1. Re-read `.agents/CODEX.md` and this plan.
2. Inspect current code and working-tree changes.
3. State the bounded milestone scope.
4. Implement only that coherent work package.
5. Add the planned high-value tests.
6. Run the milestone verification.
7. Update affected documentation in the same work package.
8. Update this plan with completion status, actual commands, results, known issues, and the next milestone.
9. Remove obsolete code made unnecessary by the milestone.

Do not overwrite unrelated user changes. Do not reset the working tree destructively. Treat all financial migrations and test-database resets as explicit-target operations with safety checks.

## 15. Definition of Done

The overall project is complete only when:

- monthly interest is automatically posted from closed month-end balances;
- every missed eligible month is recovered independently;
- every non-zero month creates exactly one transaction and one chest;
- retries and concurrency cannot duplicate money;
- partial failures cannot leave inconsistent state;
- rate history makes delayed processing deterministic;
- Zurich period boundaries are explicit and tested;
- archived periods are handled according to the confirmed policy;
- the customer sees an accurate current-balance estimate and next posting date;
- all unit, real-database, smoke, and visual tests pass;
- schema, migrations, seeds, configuration, and README are synchronized;
- legacy lazy monthly-interest behavior has been removed;
- cutover and scheduled operation are documented and rehearsed.
