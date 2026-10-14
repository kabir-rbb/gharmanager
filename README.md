# Ghar Manager — web application

Laravel 12 / PHP 8.3, Vue 3 / TypeScript and Tailwind 4. A development-stage construction workspace for Nepal with NPR integer-paisa accounting, English/Nepali labels and BS display dates.

**Not a production-ready release.** This branch implements a web operations slice; Android is unchanged. See [web operations](docs/web-operations.md), [deployment](docs/deployment.md) and [security](docs/security.md).

## Local setup

Requirements: PHP 8.3+ with SQLite/PDO, mbstring, XML, ctype, curl, fileinfo and zip; Composer 2; Node 22.12+ (tested on Node 24); Chromium for browser tests.

```bash
composer install --no-interaction --prefer-dist
npm ci
cp .env.example .env
php artisan key:generate
# .env defaults to SQLite. Create the development database:
touch database/database.sqlite
php artisan migrate
npm run build
php artisan ghar:create-user
php artisan serve --host=127.0.0.1 --port=8000
```

Visit `http://127.0.0.1:8000`. The account command prompts privately for a password; do not pass real passwords on the command line or put them into Git. Provision colleagues with the same command and add them by email from the project's **Team** screen. Registration, invitations, approval and recovery are not implemented.

Never run the default Laravel seeder or the E2E fixture against a production database. They create predictable fictional accounts for development only.

## Web capabilities

- Create projects; record materials, diary entries and consultation requests.
- Founder-controlled project membership: professional site editors and read-only members.
- Worker daily rates and full/half/absent attendance with exact historical rate snapshots.
- Manual categorized bills, partial/final payments, payee balances and bill-based budget remaining.
- Retry-stable bill/payment UUIDs, attendance replay acknowledgement, duplicate/conflict handling and overpayment protection.
- Transactional audit history from this upgrade onward; older activity is not fabricated.
- Responsive web screens and new English/Nepali labels. Server errors and some original explanatory labels remain English; full localization is still a release gate.

The project founder is the original `projects.user_id`; existing projects do not need a data backfill. All roles can read financial records and activity; only the founder can access the member account directory or write finances. There is no global admin role.

## Checks

```bash
npm run build                 # TypeScript + production assets
npm test                      # domain and Vue component tests
php artisan test              # SQLite backend feature/unit tests
vendor/bin/pint --test         # full-repository style includes pre-existing issues
composer audit --locked
npm audit
```

New/changed PHP is formatted with Pint. This branch does not reformat every original file.

### Browser tests (isolated local environment only)

Browser tests use `http://127.0.0.1:8000` and fictional founder/professional/member accounts. **Use a disposable local SQLite database and cache.** Test setup resets the local cache counters between tests while preserving the real application's rate-limit rules. The membership scenario exercises several sign-ins in one test.

```bash
php artisan migrate
php artisan db:seed --class=E2ESeeder
npm run build
php artisan serve --host=127.0.0.1 --port=8000
# In another terminal, with Chromium installed:
CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e
```

JSON results, screenshots and logs are written under `artifacts/`; failed-test traces under `test-results/`. Both are ignored by Git. The supplied CI template is stored in `docs/ci/web.yml`. GitHub rejected writes to `.github/workflows/web.yml` with the current connection, so no executable Actions workflow or remote CI result is included in this PR. A maintainer with workflow-write permission can copy the template to `.github/workflows/web.yml`, push it and review the resulting checks. Until then, run the documented checks manually in an isolated environment.

## Remaining scope

Global founder/admin workflows, invitations/approvals, registration/recovery/MFA, project editing and archival, issues, uploads/documents, scheduling/WBS/Gantt, exports/imports, notifications, bookings, subscriptions/payment gateways, complete localization and Android/offline enhancements are still pending. Financial correction/reversal workflows, normalized supplier/worker accounts and production database concurrency/load validation are also pending. No real bank transfer or online payment is initiated by the ledger.
