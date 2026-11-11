# Authentication settings

This feature is **unreleased** and is built on the Google OAuth feature
branch. The only live hostname is https://opgateway.arsamnet.com.
No new .env setting is required. OAuth configuration uses the existing
`settings` table. Google-first accounts additionally require reviewed
schema migration 008 after migration 007.

## Independent Settings pages

| Path | Purpose |
|---|---|
| `/admin/settings/security` | CAPTCHA provider, image challenge and optional reCAPTCHA keys |
| `/admin/settings/login/google` | Google OAuth Web Client ID, secret and enable/disable |
| `/admin/settings/smtp` | SMTP, email verification and password recovery |

The sidebar has a TailAdmin-style Settings parent button with bundled
Lucide icon, a smooth height/opacity submenu animation without side borders,
chevron and active-link styling. Each link navigates to a dedicated
settings category; there is no All settings landing page. Admin
authentication and role are rechecked on each request. POST changes
require CSRF but not repeat password confirmation; successful writes use
the existing metadata-only security audit table.
The old `GET /admin/settings/google` redirects to the Google SSO page;
legacy POST is unavailable.

## Google OAuth

Use a Google OAuth **Web application** Client ID and Client Secret,
obtained privately from Google Cloud Console. The fixed redirect URI is
`https://opgateway.arsamnet.com/account/google/callback`. Store both keys
through the admin page, never in Git or .env. The secret is encrypted under
the `google:oauth` context using private `storage/app-secret.key`.
Google SSO can be disabled without deleting its credentials. Continue with
Google can create a verified pending account with no local password. Google
subject IDs, not merely matching emails, identify linked users. An existing
email collision requires a separate one-time mailbox confirmation before the
subject is linked. Password recovery remains available when Google is disabled.
Google SSO is available for approved account users, not an alternate
administrator sign-in route.

## CAPTCHA provider

The login settings support exactly two alternatives:

- **Offline image** (default when no `login.captcha_provider` setting exists):
  a six-character random alphanumeric image rendered to compact grayscale
  PNG on the server, with per-character rotation, darker/thicker crossing
  lines and noise dots, and pixel displacement. The image scales down to
  the width available in the login form without overflow. It is bound to the session, one-use and valid for three
  minutes; CAPTCHA submissions are separately limited per source IP
  (30 per 15 minutes). The server stores only a keyed answer digest and
  short-lived image bytes, not the plaintext answer. PNG generation
  needs the PHP zlib extension, not GD, external HTTP or JavaScript.
  No visual CAPTCHA can guarantee resistance against modern OCR and
  vision systems; account/password throttles remain essential.
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
10 frontend tests and Webpack build. Regenerate the production-only
Composer autoloader on Mac, review/commit the two generated classmap
files and built CSS assets only (never development vendor dependencies).
Test real admin/account login, offline challenge replay/expiry/abuse
denials, Google SSO toggle, and both reCAPTCHA success/failure on HTTPS;
inspect CSP, no-store and session cookies. Confirm outbound HTTPS from
the cPanel host before selecting Google reCAPTCHA, then deploy from a
reviewed main merge only. No separate staging domain exists.

## Accessibility and release validation for image challenges

The visual PNG test cannot be completed by someone who cannot perceive
the characters. Before production, review an accessible alternative
flow (for example an equivalent audio challenge or email-based fallback
that does not bypass account verification) and check multiple browsers
and mobile screen readers. Do not claim the image alone prevents all
automated submissions. Limit challenge issuance in the deployment WAF
and test the server's capacity for repeated image requests.

Before enabling the new default image challenge, verify that the
production PHP runtime has zlib (`function_exists('gzcompress')`).
There is intentionally **no arithmetic fallback** when PNG generation
fails. A missing required image encoder fails closed and must be
identified before deployment.
