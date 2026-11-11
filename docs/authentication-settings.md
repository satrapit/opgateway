# Authentication settings

This feature is **unreleased** and is built on the Google OAuth feature
branch. The only live hostname is https://opgateway.arsamnet.com.
No new database migration or .env setting is required: use the existing
`settings` table after schema migration 007 has been reviewed.

## Independent Settings pages

| Path | Purpose |
|---|---|
| `/admin/settings` | Settings index |
| `/admin/settings/login` | Overview of password login, SSO and CAPTCHA |
| `/admin/settings/login/google` | Google OAuth Web Client ID, secret and enable/disable |
| `/admin/settings/login/captcha` | CAPTCHA provider, site key and secret |
| `/admin/settings/smtp` | SMTP, email verification and password recovery |

The sidebar uses native nested Settings / Login settings disclosures and
full page navigation. Admin authentication and role are rechecked on each
request. POST changes use CSRF and current-password verification, and
successful writes use the existing metadata-only security audit table.
The old `GET /admin/settings/google` redirects to the Google SSO page;
legacy POST is unavailable.

## Google OAuth

Use a Google OAuth **Web application** Client ID and Client Secret,
obtained privately from Google Cloud Console. The fixed redirect URI is
`https://opgateway.arsamnet.com/account/google/callback`. Store both keys
through the admin page, never in Git or .env. The secret is encrypted under
the `google:oauth` context using private `storage/app-secret.key`.
Google SSO can be disabled without deleting its credentials. An existing
password remains mandatory for account creation, linking and local fallback.
Google subject IDs, not merely matching emails, identify linked users.
Google SSO is available for approved account users, not an alternate
administrator sign-in route.

## CAPTCHA provider

The login settings support exactly two alternatives:

- **Offline** (default when no `login.captcha_provider` setting exists):
  a short arithmetic question, one-use, session-bound, valid for three
  minutes and separately limited per source IP to 30 submissions per
  15 minutes. It has **no external HTTP/JS dependency**, but it is less
  resistant to automated solvers than a mature CAPTCHA service.
- **Google reCAPTCHA v2 checkbox**: provide a matching reCAPTCHA v2
  **site key** and **secret key** from the Google reCAPTCHA administration
  console, which are **not** the Google OAuth Client ID/Secret.
  The server sends the response token to the fixed HTTPS
  `www.google.com/recaptcha/api/siteverify` endpoint with TLS validation,
  checks `success` and verifies that the returned hostname matches the
  canonical application hostname. Provider failures deny login rather
  than bypassing verification. Tokens are one-use and short-lived by
  Google's protocol.

Both Account and Administrator **password** login forms use the selected
CAPTCHA. The Google OAuth callback has its own OIDC state/nonce/PKCE and
does not use the password-form CAPTCHA. User registration retains its
existing email verification, rate limits and approval controls. CAPTCHA
does not replace the existing MySQL password-attempt throttle.

## Configuration and recovery

The keys are stored in existing MySQL `settings` rows:
`login.captcha_provider`, `login.recaptcha_site_key` and
`login.recaptcha_secret` (AES-256-GCM encrypted, `captcha:recaptcha`
context). Invalid credentials cannot activate Google reCAPTCHA.
Changing a site key requires its corresponding secret.

If Google reCAPTCHA is inaccessible or its keys are misconfigured,
login fails **closed**, including for administrators. Restore the
`offline` provider through an authenticated cPanel/phpMyAdmin session
after verified database backup by setting the single
`login.captcha_provider` value to `offline`, without altering
`storage/app-secret.key`, passwords, OAuth keys or user accounts.
Re-check login forms immediately. Do not introduce a public URL
or unauthenticated bypass to change settings.

## Verification before any single-domain release

Run the PHP lint, targeted and full PHPUnit, 10 smoke checks, npm lint,
7 frontend tests and Webpack build. Regenerate the production-only
Composer autoloader on Mac, review/commit the two generated classmap
files and built CSS assets only (never development vendor dependencies).
Test real admin/account login, offline challenge replay/expiry/abuse
denials, Google SSO toggle, and both reCAPTCHA success/failure on HTTPS;
inspect CSP, no-store and session cookies. Confirm outbound HTTPS from
the cPanel host before selecting Google reCAPTCHA, then deploy from a
reviewed main merge only. No separate staging domain exists.
