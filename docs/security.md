# Security notes for this branch

## Exposed generated application cache

The original public repository tracked `bootstrap/cache/config.php`, `routes-v7.php`, `packages.php` and `services.php`. The cached configuration contains a non-empty Laravel application encryption key. No non-empty MySQL or SMTP password was found in those cached slots during this check; this is not an exhaustive secret audit.

This branch deletes those generated files and ignores regenerated PHP cache files. The route cache also caused new route definitions to remain unavailable until cleared. **Deleting the files in a new commit does not remove them from Git history or revoke a previously published key.**

If the cached key was used in any deployed environment:

1. Treat it as compromised. Identify affected deployments and encryption use before changing keys.
2. Back up the database/files and map data encrypted with the old key. Plan controlled migration/re-encryption rather than blindly discarding a key needed to read records.
3. Provision a fresh application key securely; invalidate sessions/cookies and assess whether other tokens/credentials must be revoked. Sanctum token hashes are not simply rotated by changing `APP_KEY`.
4. Decide on a short, controlled old-key migration window if encrypted data requires it. Do not retain an exposed key indefinitely as a previous key.
5. Review repository history, CI artifacts and logs for other secrets. Any history rewrite needs coordinated approval and developer re-cloning; this branch does not force-push or rewrite history.

This work does not rotate a deployed key, revoke sessions or modify your server.

## Current controls

- Session login/CSRF for web requests; expiring/revocable Sanctum mobile tokens.
- Project-scoped authorization on every read/write; only founders manage financial/team records.
- All member roles can read project financial information: deliberately disclosed in Team.
- Integer-paisa validation/calculation, immutable financial records through the API, overpayment rejection and request idempotency.
- Transactional project locks and audit insertion; directory restriction, foreign-project worker/bill rejection and caller-ID rejection.
- Vue interpolation escapes user-generated text; browser regression tests cover original and new stored-XSS surfaces.
- Existing security headers and real login/API rate limits are retained. The local E2E fixture clears isolated test counters, not the production rules.
- No payment provider is contacted, no uploads are accepted by the new modules, and no account passwords are included in logs or audit payloads.

## Still required before real sensitive records

Recovery/MFA, verified identities, workspace-level roles/invitations, financial reversals/approval, production concurrency/load testing, full DAST/security review, deployment hardening, backup restoration/rollback rehearsals, audit retention/monitoring and comprehensive accessibility review. Package advisory scans are time-of-scan checks, not security guarantees. Android's unencrypted offline notes and device acceptance blocker are unchanged.
