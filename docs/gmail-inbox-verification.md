# Gmail inbox Verify Email action

OPGateway can add Gmail's `ConfirmAction` structured data to the **HTML MIME part**
of account verification and administrator invitation email. It preserves the
existing text/plain alternative and ordinary `/account/verify?token=...` link
for other email clients and for Gmail users who do not see the inbox button.
Google alone controls whether the inbox action appears.

## Activation (no migration and no new secrets)

1. Deploy the updated code after local test and review. All existing migrations
   remain unchanged (nine total). The setting defaults to **off**.
2. Configure SMTP and set a fixed `From` address under
   **Admin → SMTP settings**. Check SPF/DKIM alignment and deliverability.
3. Send a real verification email from production SMTP to your own Gmail inbox,
   with the **Gmail inbox verification** setting temporarily enabled in
   **Admin → SMTP settings**. The HTML message includes JSON-LD for a Gmail
   `ConfirmAction`; the ordinary verification link still works. Do **not** expect
   Gmail to show the button before sender approval; inspect the message's
   original HTML and test its schema instead.
4. Validate the message with Google's Email Markup Tester and send a real
   markup-bearing sample **directly** from the production sender to
   `schema.whitelisting+sample@gmail.com` (not forwarded). Submit Google's
   [sender registration form](https://developers.google.com/workspace/gmail/markup/registering-with-google).
5. Once approved, enable the toggle for production use. Disabling the
   setting both stops emitting action markup on newly sent emails **and blocks
   pending Gmail one-click POST actions** in previously sent emails. The
   ordinary verification link remains available; tokens remain single-use
   and expire after 30 minutes.

Google typically requires an authenticated/aligned sender, static `From`
address, sustained Gmail volume (hundreds of daily messages for weeks), low
spam complaints, and approval. A low-volume service may not qualify.
Sending markup without sender registration **does not guarantee** a button.
Do not include real verification tokens in public screenshots, tickets, or logs.

## Implementation and threat model

- The POST handler is `/account/verify/gmail?token=<64 lowercase hex>`.
- The same 30-minute, hashed-at-rest, atomic single-use token protects
  both the inbox action and normal email confirmation flow. A successful
  confirmation via either path invalidates the other.
- The handler only accepts a POST with a bounded form body
  `confirmed=Approved`, a Gmail Actions user-agent, and a valid token.
  GET, malformed/expired/replayed tokens, or disabled feature fail closed.
  **User-Agent is spoofable** and is not a Google identity proof. Possession
  of the one-time secret is the authorization, just as for the ordinary
  email verification link. URLs containing tokens must not be logged.
- Google does not attach cryptographic Bearer Authorization to action
  requests by default. For stronger Google-origin attestation, explicitly
  request signed bearer tokens from Google during registration and add
  cryptographic validation before treating the caller as Google. This
  version does **not** claim such attestation.
- Account recovery emails and Google account-link confirmation emails
  are deliberately excluded from Gmail inbox actions.
- Toggle state is an additional `settings` key,
  `smtp.gmail_actions_enabled`; there is no new table or migration.
- The POST handler is a deliberate exception to browser CSRF checks,
  guarded by the one-time secret. Do not expose this endpoint as GET
  or use an unbounded/reusable token.

## Verification

After checking out the feature branch:

```sh
php scripts/lint.php
./vendor/bin/phpunit tests/GmailVerificationActionTest.php
./vendor/bin/phpunit tests/PdoSmtpSettingsTest.php
./vendor/bin/phpunit --display-phpunit-notices
php scripts/smoke.php
```

When performing live acceptance testing, verify the original link works
without Gmail markup, the one-click action consumes the token only once,
expired tokens cannot activate an account, and SMTP failures do not expose
the token in logs.
