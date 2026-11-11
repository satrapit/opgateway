# Frontend architecture

Both administrator and owner portals use **one** server-rendered Twig 3
design system styled with locally built TailAdmin Free/Tailwind CSS v4 and
CSP-safe Alpine components. There is no alternate hand-written HTML
renderer and no environment switch for choosing an interface. Owner login,
registration, recovery, Account Security, authenticated dashboard, provider/client/IP
forms and one-time API key display all use the same visual foundation.

## Structure

- `templates/layouts/`: shared auth layout, shared workspace shell and
  small role-specific Admin/Account navigation; both have the identical
  header height, theme control, CSS components, drawer behavior and icons.
- `templates/components/`: escaped navigation, forms, record cards,
  accessible details and dialogs.
- `templates/admin/`: admin login, overview, approvals, clients, IPs and
  database-backed SMTP Settings page.
- `templates/account/`: account login, signup, verification, status,
  dashboard and one-time-key screen. `AccountViews` renders these through
  the same strict, autoescaping `TwigRenderer` as the Admin UI.
- `src/View/TwigAdminPageRenderer.php`: presentation-only adapter;
  authentication and queries happen before rendering.
- `frontend/`: local Tailwind/Alpine/JS sources, with Webpack build.
- `public/assets/admin/`: committed, versioned CSS/JS/SVG and
  restricted cache headers.

## Design and usability

Follow the reviewed TailAdmin Free/MIT design language: brand-500
`#465fff`, neutral surfaces, rounded cards, compact navigation and
accessible dark/light/system themes. The 80px header baseline aligns
sidebars and main content on desktop in both portals. The responsive
sidebar closes on Admin or Account navigation; Admin active links are
server-driven and Account anchors navigate to its real dashboard sections. Use
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
Inspect regenerated CSS/JS and asset URL hashes; **the owner UI source
changes require a fresh local Webpack build** and committing the generated
`public/assets/admin/app.css` and `app.js` if changed. Inspect both
Admin and Account CSP/JS console warnings. Browser-test 320, 375,
390, 768, 1024 and 1440px, keyboard Tab/Escape/focus, light/dark/system
theme and authenticated action forms. Build output is committed for the
shared cPanel host; no runtime Node/Composer/npm build on Production.

This refactor is unreleased until independent local SMTP, account, provider
and security end-to-end checks pass.
