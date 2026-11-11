# Configuration locations and private .env cleanup

OPGateway has one private .env file. Only these five keys are supported:

```dotenv
DB_HOST=db
DB_PORT=3306
DB_NAME=YOUR_EXISTING_DATABASE
DB_USER=YOUR_EXISTING_USER
DB_PASSWORD=YOUR_EXISTING_PASSWORD
```

The shared-host production database host is `db`. Retain the real values
from your existing production configuration. Do not paste actual database
credentials, encryption keys or tokens into chat, Git or issue comments.

The loader `Environment::load()` and connection class
`DatabaseConfig::fromEnvironment()` reject unknown keys. They do not
silently interpret old security switches. There is no `getenv()` /
`$_ENV` fallback. `.env.example` lists the same five names.

## Where the other settings actually live

| Setting | Location |
|---|---|
| Admin and account identity, approval and role | `users`, `user_roles` |
| Per-IP/per-email login throttling | `REMOTE_ADDR`, `login_rate_limits`, derived HMAC key |
| API client IP whitelist | `client_ip_whitelists` (approved exact IP per enabled client) |
| SMTP | `settings` table: `smtp.*` (encrypted password); `/admin/settings/smtp` |
| Google OAuth (under development) | `settings` table: `google.*` (encrypted secret); `/admin/settings/login/google` |
| Login CAPTCHA (under development) | `settings` table: `login.captcha_provider`, `login.recaptcha_site_key`, `login.recaptcha_secret` (encrypted); `/admin/settings/security` |
| Openprovider account credentials | `provider_connections` (encrypted values) |
| Encryption and HMAC seed | Private `storage/app-secret.key` (0600), **not .env** |
| Canonical URL | Trusted web server `SERVER_NAME`, matching `HTTP_HOST` |
| Allowed upstream operations | Fixed code in `OpenproviderRoutePolicy` |

Admin **browser** login is not restricted by an .env IP list.
Its source IP is used for login throttling: 30 attempts/IP and
8 attempts/identity in a 15-minute window. Gateway API access requires
the separate MySQL exact-IP approval rule. External network WAF or
firewall rules are outside the application .env contract.

Historical keys such as `APP_KEY`, `ADMIN_IP`, `ADMIN_ALLOWED_IPS`,
`ALLOWED_IPS`, `ADMIN_TWIG_UI_ENABLED`, `ADMIN_ACTIONS_ENABLED`,
`SMTP_HOST`, `GOOGLE_CLIENT_SECRET`, `OPENPROVIDER_USERNAME` or
other non-DB settings should **not** remain in this application's .env.
Do not move their values into the database blindly; manage them using
the corresponding authenticated UI when that feature is released.

## Safe cleanup in production on one domain

1. Verify the live release is healthy and take a secure backup of its
   private `.env` before editing. Do not commit the file or store its
   backup in the public Document Root.
2. In cPanel File Manager, open
   `/home/arsamnet/repositories/opgateway/.env` (the private file
   loaded by `public/index.php` for the deployed checkout).
   This is **not** a `.env` in the website Document Root.
3. Keep the five DB entries and their **existing values**.
   Remove other keys and duplicate entries. Do not add provider or
   IP security switches to `.env`.
4. **Do not delete or regenerate** `storage/app-secret.key`.
   It is needed to decrypt existing provider and SMTP credentials.
5. Recheck `/health`, `/admin/login`, `/account/login` and perform
   a real account/admin login. A 200 from `/health` alone does not
   prove database connectivity.
6. If authentication fails, restore the privately backed-up `.env`
   and inspect the private request-ID diagnostics. Avoid sharing
   any secret or connection value.

The OPGateway application intentionally never rewrites a production
`.env` file. On cPanel, review and edit it manually.
