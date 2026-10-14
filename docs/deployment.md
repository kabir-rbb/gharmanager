# Deployment and rollback — web operations migration

These are instructions, **not a report that production deployment or rollback has occurred**. Test them in a production-like staging environment first. See [security notes](security.md) before reusing a previously committed application key.

## Upgrade checklist

1. Review the PR, role visibility policy and bill-based cost semantics. Confirm only trusted people will receive project membership.
2. Back up the database, existing `.env`/secret configuration and uploaded files. Verify backups are recoverable in staging. Keep a known previous application release.
3. Enter maintenance mode for the release; prevent writes while switching code/migrating. Deploy the branch/tag into a new release directory.
4. Install locked Composer dependencies (`--no-dev --optimize-autoloader`) and build assets in a trusted build environment (`npm ci && npm run build`). The CI template must first be installed at `.github/workflows/web.yml` by a maintainer with workflow-write permission; no remote CI run has been claimed for this PR. Do not copy `.env`, test databases or E2E credentials from this workspace.
5. Supply production secrets independently; ensure HTTPS, secure session cookies and `APP_DEBUG=false`. The new command `php artisan ghar:create-user` provisions an account interactively; it does not grant global administration.
6. Clear old configuration/route/view caches. Run `php artisan migrate --force`. The new additive migration creates project members, workers, attendance, bills, payments and audit events. Existing project founders continue to be the original owner IDs.
7. Rebuild environment-specific caches on the destination (`config:cache`, `route:cache`, `view:cache`). Never commit or transfer cached configuration from development. Laravel runtime directories must be writable by the application user.
8. Verify founder login, project scoping, professional/member restrictions, attendance, manual bill settlement, audit entries and previous diary/material/consultation routes in staging. Exercise the real production database with simultaneous payment and membership-revocation requests before production approval.
9. Exit maintenance mode only after smoke checks pass; monitor 4xx/5xx rates, lock contention and logs. CI passing alone is not deployment acceptance.

## Rollback

- Keep the database migration in place if reverting application code and retaining the new records; the previous application should ignore the additive tables. Prior code will no longer expose shared projects, attendance, bills, payments or audit history.
- Do **not** casually run `migrate:rollback`: the new migration's `down()` drops all six operations tables and their data. Export/back up those tables first, stop writes and obtain explicit approval if destructive rollback is necessary.
- If a verified database/file restore is required, rehearse it first, restore the matching code and secrets, invalidate stale caches and check record counts and login/project ownership before reopening.
- Do not copy the original committed config cache back as part of rollback.
- Key rotation/re-encryption rollback is a separate security plan; ensure encrypted data can still be recovered without leaving the exposed key active indefinitely.

Production database/file restoration and deployment rollback have **not** been rehearsed in this task. Android deployment is out of scope.
