# Google-first account onboarding and optional local password

Status: implementation specification only. **Not production ready.** No routes or tables should be changed in production until this branch has executable coverage and has been reviewed.

## User-facing contract

- "Continue with Google" works for both sign-in and first registration. It must never require creating a local password or require a previous manual Google connection.
- Existing Google-linked users are identified by the validated OIDC issuer/audience/nonce and the stable `sub`, not their email.
- New Google users receive a local user row with `password_hash = NULL` and a verified email only when the provider's `email_verified` assertion is true. They must satisfy the existing account-approval and role policy before the dashboard or API operations are available.
- If Google's verified email matches an existing account that is **not** linked to that subject, do not sign it in by email alone. Require a one-time email ownership confirmation sent to the existing account address, and bind this confirmation to the active OIDC transaction and intended user. Never merge solely on mailbox equality. Repeat Google sign-ins use the stored subject with no extra confirmation.
- Existing password users retain their working password login, signup, password reset, and email verification.
- Account Security is a separate account page with `Set password` (no password yet), `Change password` (existing password), Google link/unlink controls, and status of available sign-in methods.
- Password is optional for Google-created users. A working `Forgot password` flow can set a password for an eligible Google-only account after email verification, even if Google login is disabled.
- Never allow unlinking the last active authentication method without first establishing a recovery method. Account approval, API-key ownership and IP allowlist policies remain enforced.

## Implementation coupling to resolve

1. Add migration 008 to make `users.password_hash` nullable while preserving all current hashes. Never store a dummy/generated password; no existing rows deleted.
2. Extend login repository, authenticator and password-reset service for nullable hashes; a NULL hash must never pass password verification.
3. Refactor `BrowserSession` and password-stamp invalidation to use a non-null, server-controlled authentication-session version or equivalent revocation mechanism. Include a migration/backfill and tests for revocation after password set/reset, disabling users and unlinking Google.
4. Extend verified OIDC client result with `email_verified`; reject unverified provider email for signup/linking. Keep state, nonce, PKCE and one-time consumption.
5. Implement transactional subject-unique Google onboarding and audited linking. Race-safe duplicates; complete pending accounts with verified email but do not bypass admin approvals.
6. Add email confirmation flow for an existing unlinked local account; fail closed if mail delivery unavailable. Treat subject conflicts and email collisions separately.
7. Replace password-only step-up in owner API-key issue and upstream Openprovider credential management with recently verified Google or current password, bound to this account and action. Preserve CSRF, throttles, scopes, security-event recording and fail-closed handling.
8. Add Account Security set/change password endpoints with CSRF, rate limits, reauth for replacing an existing hash, and generic email-based recovery.
9. Add metadata-only audit events for Google login/signup/link/unlink and password setting; no email, Google sub, ID token, access/refresh token, codes, password, request body or raw exception in logs.
10. Update TailAdmin/Twig login, register, forgot/reset and Account Security pages; prioritize Google CTA, center concise form, preserve mobile-safe responsive offline CAPTCHA and accessible errors. Do not promise automatic Google sign-in until backend is ready.
11. Update documentation and full-schema new-install SQL, preserving migrations 001–007 and existing rollback compatibility.

## Required executable checks before merge

- Existing 231 PHP tests, 7 frontend tests and 10 smoke checks remain green; add new targeted tests.
- New tests: Google-created user no password; repeat subject login; approved vs pending; no email_verified; collision with existing account requires email confirmation; subject already linked elsewhere; concurrent callbacks; rejected/replayed/expired state; password set/reset from NULL; loss of Google access; disabled Google provider; no last-method unlink; user suspension; existing password login; session revocation; API-key/upstream step-up for Google-only; event log redact/allowed codes; no user enumeration; form keyboard/mobile layout.
- Run `composer install --prefer-dist && php scripts/lint.php && ./vendor/bin/phpunit --display-phpunit-notices && php scripts/smoke.php`, plus `cd frontend && npm ci && npm run lint && npm test && npm run build`.
- Production has seven schema migrations. Migration 008 must be verified against a backup locally; provide a code/database rollback that does not discard Google-only users. Do not deploy it before the new application code is ready.
- No GitHub workflows, no embedded secrets, no automatic migration, no deployment as part of merging.
