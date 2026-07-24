# Monthly Interest Operations

## Scheduler Contract

Run the posting command at least hourly from the deployed repository root:

```sh
0 * * * * cd /path/to/bababank && /usr/bin/php tools/post-monthly-interest.php
```

The scheduler's timezone does not determine month close. The command converts its current instant to `Europe/Zurich` and only processes calendar months that have closed there. The database session stores effective instants in UTC.

Capture both stdout and stderr in the scheduler's normal log facility. The command emits one JSON document per invocation and uses these exit codes:

- `0`: successful, no work due, or an overlapping invocation was safely skipped;
- `1`: one or more customer periods failed and require a later retry;
- `2`: invalid command arguments or forbidden test-only time injection.

The command takes a database advisory lock. If another invocation already owns it, the new invocation exits successfully with `status: "skipped"` and `totals.skipped: 1`. Customer-period uniqueness and customer row locks remain the financial safety boundary if commands are retried.

## Result Totals

The JSON `totals` object distinguishes:

- `created`: non-zero periods committed with a transaction and reward chest;
- `zero_settled`: periods committed as auditable zero settlements without money or a chest;
- `already_settled`: eligible periods found from an earlier successful run;
- `skipped`: an invocation skipped because another command holds the advisory lock;
- `failed`: due periods that rolled back and still require recovery;
- `would_create` and `would_zero_settle`: dry-run projections only.

Processing is transactional per customer. If one customer fails, all work for that customer rolls back, the error is included without a stack trace, later customers continue, and the command exits `1`. An hourly retry safely handles the failed customer while already committed customers are reported as `already_settled`.

## Dry Run and Recovery

Preview all currently due work without changing rows or consuming auto-increment values:

```sh
php tools/post-monthly-interest.php --dry-run
```

Limit a dry run or recovery attempt to one customer:

```sh
php tools/post-monthly-interest.php --dry-run --customer=123
php tools/post-monthly-interest.php --customer=123
```

Recovery uses the same processor as scheduled execution. There is deliberately no public HTTP maintenance endpoint.

An injected clock is available only to automated tests:

```powershell
$env:BABABANK_ENV_FILE = ".env.test"
php tools/post-monthly-interest.php --dry-run --as-of=2026-11-01T00:00:00+01:00
```

Production invocations that supply `--as-of` exit with code `2`.

## Incident Checks

For a missed scheduler window:

1. Run `php tools/post-monthly-interest.php --dry-run`.
2. Review `would_create`, `would_zero_settle`, and the customer/period details.
3. Run `php tools/post-monthly-interest.php`.
4. Confirm exit code `0`, `totals.failed: 0`, and the expected created/zero totals.
5. Run it once more if desired; all processed periods should be `already_settled`.

If the command exits `1`, use the `errors` customer IDs and period results to investigate database/rate/eligibility data, then retry the same command. Do not insert posting, transaction, or reward rows manually.
