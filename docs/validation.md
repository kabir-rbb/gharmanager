# Local validation — web operations branch

Date: 2026-10-14. Environment: PHP 8.3.33, Node 24, SQLite, sandbox Chromium. Base: `da674bd` (`First Draft`). No production service was changed. Android was not built or tested in this task.

| Check | Outcome |
| --- | --- |
| Laravel PHPUnit | 43 passed, 295 assertions |
| Vue/domain/component tests | 13 passed |
| Live Chromium browser workflows | 10 passed |
| TypeScript check and production web assets | Passed via `npm run build` |
| Changed PHP formatting | Passed after Pint formatting |
| PHP syntax checks | Passed |
| Disposable SQLite migration up / down / reapply | Passed; all six operations tables recreated |
| Composer locked advisory scan | No advisories or abandoned packages reported |
| npm advisory scan | Zero reported vulnerabilities |
| Narrow English/Nepali screens | Browser checks passed with no horizontal page overflow |
| Production database concurrency/load | Not run; release gate |
| Production backup restore / deployment rollback | Not rehearsed |
| Full WCAG / assistive-technology review | Not completed |
| GitHub Actions | Template published at `docs/ci/web.yml`; executable workflow upload rejected by GitHub permissions; no remote run |
| PR publishing | Branch published; [PR #1](https://github.com/kabir-rbb/gharmanager/pull/1) opened, not merged |

**66 local automated tests passed.** The migration's `down()` was exercised on a disposable empty test database; this is not a production restoration/rollback rehearsal.

Browser coverage includes original project/material/diary/consultation workflows, account sign-in/out, site-writer/read-only behavior, founder role assignment and revocation, labor rate calculations, partial/final bill settlement, overpayment rejection, payment replay after a deliberately lost response, reload persistence, new-module CSRF rejection, stored-XSS escaping, English/Nepali narrow layouts and audit visibility. Backend coverage additionally checks outsider isolation, foreign worker/bill rejection, immutable founder access, unsupported role/currency inputs, UUID conflict/replay rules, audit pagination and rollback if audit insertion fails. Account provisioning tests verify password hashing and confirmation.

Visual captures use explicitly fictional construction records. Advisory scans are snapshots, not guarantees. This development branch must not be described as a completed launch release or used for sensitive client accounting.

## Published branch

The application changes were published using the authenticated GitHub connection. GitHub rejected only the executable workflow path, so the same workflow is retained as a non-executable template in `docs/ci/web.yml`. Application source was compared to locally tested commit `bdd863e12e27009d88a10bf7f1a523b81b82d0e6`; only workflow placement and publishing-status documentation differ. The original local commit is preserved separately.
