# Frontend architecture

The administrator dashboard uses **one** server-rendered Twig 3 UI styled
with locally built TailAdmin Free/Tailwind CSS v4 and CSP-safe Alpine
components. There is **no alternate legacy HTML administrator renderer**
and no environment flag to switch interfaces. Owner pages are currently
lightweight server-rendered HTML and do not require a JS runtime.

## Structure

- `templates/layouts/`: reusable admin shell and auth layout.
- `templates/components/`: escaped navigation, forms, record cards,
  accessible details and dialogs.
- `templates/admin/`: login, overview, approvals, clients, IPs and
  database-backed SMTP Settings page.
- `src/View/TwigAdminPageRenderer.php`: presentation-only adapter;
  authentication and queries happen before rendering.
- `frontend/`: local Tailwind/Alpine/JS sources, with Webpack build.
- `public/assets/admin/`: committed, versioned CSS/JS/SVG and
  restricted cache headers.

## Design and usability

Follow the reviewed TailAdmin Free/MIT design language: brand-500
`#465fff`, neutral surfaces, rounded cards, compact navigation and
accessible dark/light/system themes. The 80px header baseline aligns
sidebars and main content on desktop. The responsive sidebar closes
after navigation; active links derive from the server section. Use
non-overflowing card-based details on mobile widths 320–390px.
Actions are proper CSRF-protected forms and remain usable without JS.
The local `<dialog>` enhancements add escape/backdrop dismissal, focus
restoration and confirmations for destructive changes.

Never place user credentials, bearer keys or CSRF values in browser
localStorage. The Theme preference alone may be remembered locally.
Require same-origin assets: no public runtime CDN, remote fonts, analytics
or trackers. Twig must escape all untrusted user/database strings and
accept only explicit allowlisted template/view names.

## Build and verification

On the trusted Mac run `composer install`, PHP lint, PHPUnit and smoke;
then `cd frontend && npm ci && npm run lint && npm run build`.
Inspect versioned assets and JS/CSP warnings. Browser-test 320, 375,
390, 768, 1024 and 1440px, keyboard Tab/Escape/focus, light/dark/system
theme and authenticated action forms. Build output is committed for the
shared cPanel host; no runtime Node/Composer/npm build on Production.

This refactor is unreleased until independent local SMTP, account, provider
and security end-to-end checks pass.
