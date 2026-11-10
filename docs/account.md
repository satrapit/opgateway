# Approved owner account and API credentials

The owner portal is opt-in and separate from administrator management. The proposed next release adds registration and email verification, owner Openprovider credential entry, disabled client creation, and IP approval requests. Only active, verified and approved users can log in; accounts created via new self-service become approved after email verification, while manually created accounts still need administrator approval.

## Private .env

```dotenv
APP_URL=https://opgateway.test
ACCOUNT_UI_ENABLED=false
ACCOUNT_KEYS_ENABLED=false
GATEWAY_PROXY_ENABLED=false
LOGIN_RATE_LIMIT_KEY=
```

Both account flags default to false. `ACCOUNT_UI_ENABLED=true` enables secure account login and read-only ownership views; `ACCOUNT_KEYS_ENABLED=true` separately enables issuing/revoking keys. The proposed next release removes the browser admin IP gate while retaining the client API IP whitelist. Never enable either account flag in Production before successful end-to-end review.

## Routes

| Method | Path | Behavior |
|---|---|---|
| GET / POST | `/account/login` | Secure session/CSRF sign-in with MySQL-backed per-IP and per-identity limits |
| GET | `/account` | Owner-scoped clients, nonsecret key prefixes and IP status |
| POST | `/account/keys/issue` | CSRF, current session, fresh password reconfirmation, owned active client |
| POST | `/account/keys/revoke` | Revoke one key belonging to a client owned by this account |
| POST | `/account/logout` | CSRF-protected sign-out |

- Password failures have generic responses. The login session uses Secure, HttpOnly, SameSite=Strict cookies and a 30-minute absolute lifetime, with DB account eligibility rechecked on protected requests.
- Issuance returns the new random 256-bit bearer key only in the successful HTTPS POST HTML response. Refreshing with the old CSRF cannot issue another key; no key is put into a URL, session, log, analytics, or GET response. Only the indexed prefix and SHA-256 digest are stored.
- Issuance and revocation use owner-scoped, parameterized SQL, separate HMAC rate counters (10 per owner, 30 per source IP per 15 minutes), and metadata-only audit in the same DB transaction. Failed audit writes roll back the credential change.
- The account page displays at most 50 owned clients and 100 keys/IPs. Full credentials and password hashes are never read into the dashboard.
- An issued key still cannot reach Openprovider: public `/v1` routes remain explicitly disabled. API admission also requires an approved exact client source IP when implemented.

## Local end-to-end verification

1. Pull the latest commit and run lint, PHPUnit (with notices displayed) and smoke checks.
2. On your **independent local database only**, keep `GATEWAY_PROXY_ENABLED=false` and set both account flags to `true`.
3. Use the email of the previously approved test user and its known password. If its original random password was discarded, reset it using the local-only helper. Verify that `.env` has `APP_ENV=local`, `APP_URL=https://opgateway.test` and a loopback `DB_HOST=127.0.0.1` or `::1`, and double-check the database is **not** tunneled to production:

   ```bash
   cd ~/Sites/opgateway
   printf 'New local password: '
   read -rs pw
   printf '\n'
   printf '%s\n' "$pw" | php scripts/local-set-account-password.php 'approved-test-user@example.test'
   unset pw
   ```

   Replace `approved-test-user@example.test` with the actual approved user's email. The helper reads the password from STDIN and changes only that eligible account's local password hash.
4. Sign in at https://opgateway.test/account/login and confirm the dashboard lists only that user's active/disabled clients, approved/pending IPs, and nonsecret key prefixes.
5. For an **active test client**, confirm the account password again to issue a key. Copy it securely as it is displayed once. Returning to the dashboard must display the prefix only.
6. Revoke the test key. Confirm the row is marked revoked, and the old key would be ineligible for admission. Do not paste secrets into conversations, tickets, logs or screenshots.

Production flags should stay off while testing. No schema migration or cPanel deployment automation is added.

## Not yet enabled

Public signup, verification email delivery, owner-created clients/IP requests, Openprovider upstream transport, and external API acceptance remain outside this phase.


## Next release routes (not yet deployed)

- GET/POST /account/register — user signup with email/password and CSRF
- GET /account/verify?token=... — show one-time confirmation form only
- POST /account/verify — CSRF-confirm and consume token
- POST /account/verify/resend — HMAC-throttled resend
- POST /account/provider/save — save encrypted Openprovider credentials
- POST /account/clients/create — create disabled owned client
- POST /account/ips/request — create pending exact-IP allowlist entry

These actions are separately gated by ACCOUNT_REGISTRATION_ENABLED and
ACCOUNT_INTEGRATION_ENABLED. See gateway-onboarding.md for complete flow.
