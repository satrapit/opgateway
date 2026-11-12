# Shared user profile and action gating

Administrators and account owners authenticate against the existing `users` table. Roles remain in `roles` and `user_roles`; there is no separate administrator identity table.

Migration 009 adds nullable `first_name` and `last_name`. Existing user data and administrator roles are preserved. No fallback or dummy names are assigned. Names are validated as UTF-8 and limited to 100 Unicode characters each.

Authenticated account owners may view their dashboard and visit `/account/profile`. Until both names are valid and non-empty, account mutations (client creation, IP changes, API key issuance/revocation, provider updates and security mutations) are blocked server-side with a redirect to profile completion. The dashboard shows a reminder.

Google OIDC may provide verified-token `given_name` and `family_name` to prefill new accounts. Users who have no usable names complete them inside the workspace. Password-based registration also completes names after email verification and sign-in.

The recovery form enforces the configured offline image CAPTCHA or Google reCAPTCHA before requesting a reset email. Password reset link consumption is unchanged.

**Deployment:** back up the existing eight-migration database and private secret; review and execute the additive SQL upgrade 009 manually in phpMyAdmin BEFORE deploying the updated PHP code. Confirm nine completed migrations and unchanged user counts. Do not rerun the full initial schema or edit previous migration checksums.
