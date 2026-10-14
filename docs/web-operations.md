# Web operations slice

## Project authorization

| Capability | Founder | Professional | Member |
| --- | --- | --- | --- |
| Read project, site records, ledger and activity | Yes | Yes | Yes |
| Materials, diary, consultation requests, workers, attendance | Write | Write | Read only |
| Bills and payments | Write | Read only | Read only |
| Member account directory and role/access changes | Manage | No | No |

The immutable project founder is its existing `user_id`, not a global administrator. A user can own one project and be a professional/member in another. Existing accounts are added by exact email; no invitation is sent, no approval is implied and no new account is created by this endpoint. Revocation is effective on subsequent API requests. Every mutation is checked on the server; hidden buttons are not the security boundary. Non-members receive 404, known project members lacking a capability receive 403. Caller-supplied owner/actor/project IDs never establish authority.

All roles can see finances and audit history. The UI explicitly warns the founder before adding members. User emails are restricted to the founder's directory endpoint; passwords/tokens are never returned or copied into the activity log.

## Labor

- Workers have a name, trade and positive daily rate in integer paisa.
- Full day = 1000 milli-days; half day = 500; absent = 0.
- Cost = `(milli_days * daily_rate_paisa + 500) // 1000`, i.e. nearest paisa, halves rounded upward.
- Attendance stores the applied rate and cost as historical snapshots.
- One entry per worker/date. Exact retries acknowledge the existing entry; differing retries return 409 instead of silently overwriting it.
- Future attendance is rejected using the Nepal calendar day; foreign-project worker IDs are rejected.
- Worker editing, overtime, attendance corrections and labor settlement against normalized worker accounts are pending.

## Bills, payments and budgeting

Amounts are submitted as decimal **strings**, validated to at most eight whole digits and two fractional digits, then converted to integer paisa. Positive amounts are required. No float-based accounting is used on the server.

The operational material and labor registers are estimates. **Bill amounts alone are the ledger's incurred/billed cost.** Enter each supplier/worker bill once; do not add the bill total to the material/attendance total. A payment reduces outstanding debt; it does not increase incurred cost. Budget remaining = project budget minus billed costs. Negative remaining is displayed as over budget. This is a bill-based view, not a complete accounting or cash-flow forecasting module.

Bills carry project-unique references, payee names, categories (`material`, `labor`, `other`), descriptions and issue dates. Payee names group balances exactly as stored: use consistent spelling. There is no supplier master, worker master linkage, tax/retention, due-date aging, receipt upload or currency conversion.

Payments refer to a bill **inside the same project**, carry a paid date, manual method (`cash`, `bank`, `other`) and optional reference. Dates before the bill or after the Nepal day are rejected. Overpayments, zero/negative amounts and malformed decimal values are rejected. Method labels only describe a manual record: they never initiate a bank transfer or gateway charge.

Bills/payments have project-scoped client UUIDs. Same UUID + same normalized content returns the original row with 200; differing content returns 409. New records return 201. The web form preserves its UUID while open, including after a lost response, to make retries safe. Closing/reopening generates a new UUID; refresh/reopen does not restore an in-flight form. Financial records are append-only through these APIs; corrections/reversals and complete accounting controls are future work. Review entries before saving; this development slice is not suitable for live accounting.

All project mutations obtain the same project-row write lock and run in a transaction. Membership changes, bill settlement, site writes and audit insertion use this common boundary. Bill settlement also locks the bill row. This design serializes concurrent project writes on a transactional database supporting row locks. SQLite checks verify transactional rollback and sequential settlement/replays; **simultaneous production MySQL/PostgreSQL contention and load have not been verified**. Busy SQLite writes may require a retry. Production database acceptance remains a release gate.

## Audit history

Writes to projects, materials, diary, consultations, members, workers, attendance, bills and payments record the authenticated actor, action, subject, UTC time and a bounded contextual payload inside the same transaction. Failed writes/audits roll back together. Exact replays/no-op role changes do not generate duplicate events. Diary text and account credentials are not duplicated into the audit payload.

Audit events are readable to authorized project roles, returned newest-first with a maximum of 50 per response and an exclusive `before` cursor. Dates are returned with explicit UTC offsets and displayed in Nepal time. No history is backfilled for pre-upgrade activity. There is no public update/delete audit API, but this is **not cryptographic or database-administrator-proof tamper protection**. Account deletion, retention and production log controls require a later design.

## Routes

Existing project/site API paths remain available. New authenticated, throttled routes:

| Method | Path | Required capability |
| --- | --- | --- |
| GET | `/api/projects/{project}/members` | Founder |
| POST | `/api/projects/{project}/members` | Founder; email + professional/member role |
| DELETE | `/api/projects/{project}/members/{user}` | Founder; cannot remove founder |
| POST | `/api/projects/{project}/workers` | Site writer |
| POST | `/api/projects/{project}/attendance` | Site writer |
| POST | `/api/projects/{project}/bills` | Founder |
| POST | `/api/projects/{project}/payments` | Founder |
| GET | `/api/projects/{project}/audit?before={id}` | Any authorized project role |

Project listings now include shared projects and their `role`. Detail responses add `role`, `permissions`, workers/attendance, bill/payment records and ledger totals while retaining original material/log/consultation fields. Existing Sanctum token authentication remains supported, but Android has not been updated/tested for shared-role behavior.
