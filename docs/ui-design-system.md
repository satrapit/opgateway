# OPGateway admin design system — TailAdmin Free adaptation

**Primary source:** TailAdmin Free MIT repository, with its free theme definitions from `src/css/style.css`. This project does not use TailAdmin Pro or third-party UI libraries.

## Shared tokens
- `brand-500 #465fff`, `brand-600 #3641f5`, `brand-50 #ecf3ff` and `brand-950 #161950`.
- Tailwind grays and near-white dashboard surface. Accessible light/dark text and border contrast with status labels (not color alone).
- Font stack: `Outfit` when a locally hosted licensed copy is available, else system UI sans-serif (no Google Fonts request).
- Subtle shadow `0 1px 3px rgba(16,24,40,.1), 0 1px 2px rgba(16,24,40,.06)`; 10–16px component radii; ergonomic 44px controls.
- Responsive grid breakpoints: 375, 425, 640, 768, 1024, 1280, 1536px. Test 320px explicitly.
- Use Tailwind dark variant anchored to `html.dark`; default follows `prefers-color-scheme`. Manual theme selection: system / light / dark; store only `opgateway-theme`.
- Support logical-direction margins/paddings and future RTL without separate markup.

## Component vocabulary
Navigation: full-height side rail on desktops and accessible drawer on mobile; grouped Overview / Approvals / Clients / IP Allowlist anchors, never dead future module links.

Data: small summary cards labeled “visible / up to 50”, bounded tabular sections and mobile cards. State labels: Pending, Active, Disabled, Approved, Revoked — always include text. Each empty state clearly states there are no records currently listed.

Forms: reusable POST macro with hidden `_csrf`, exact `action`, `target_id` and `value` when the real backend requires them. Never add arbitrary extra form fields; preserve the strict parser. Make destructive operations distinguishable and ask for optional client-side confirmation, but authorization remains entirely server-side.

## Accessibility checklist
Semantic `main`, `nav`, `header`, `table`, `th scope`, explicit inputs and labels, keyboard-operable buttons, 44px touches, visible focus, status text, reduced-motion handling, modal/drawer focus management. No color-only statuses or fake loading spinners. Test layouts and keyboard/a11y with actual browsers before claiming WCAG AA.

## Legal / performance
Keep MIT and original copyright for actual reused source. Import only tokens and core primitives; no charts, date picker, third-party map or paid components in this first slice. No CDN, tracking, network fonts or unnecessary effects.

## Source and build entrypoints

`frontend/src/css/app.css` imports Tailwind v4 with TailAdmin Free-compatible brand tokens and shared semantic components, `frontend/src/js/app.js` uses the Alpine CSP build for theme/drawer/confirmation behavior, and `frontend/src/js/theme-init.js` resolves the light/dark/system preference before styles. `frontend/webpack.config.cjs` builds into `public/assets/admin/`, with no external runtime CDN. These are **source artifacts** until `npm run build` has been run and the compiled files verified and committed. The admin Twig feature flag must remain off before that.

## Local icon library and responsive records

Lucide SVG icons are imported selectively from the `lucide` npm package and
bundled into `public/assets/admin/app.js`. Templates declare
`data-lucide="icon-name"`; the single global bootstrap replaces them with
inline SVG, with no network dependency, external font, inline JavaScript or CDN.
Icons are decorative unless an accessible text alternative is provided by a
button label / ARIA label. The theme control is icon-only, announces the current
mode, and cycles system → light → dark; its SVG visibility is CSS-controlled
from `html[data-theme]`. Preserve `frontend/LUCIDE-LICENSE` in distributions.

At widths below 1280px, review queues render server-side semantic record cards
instead of horizontally scrolling tables. Wider layouts retain the desktop
tables; both representations use exactly the same authorized bounded data,
conditional action form macros and server-issued CSRF tokens. All five queues
are covered. Record values (IPv6, emails, client labels) wrap at word boundaries
and overflow points. The Sidebar sets the active `aria-current="location"`
on click and `hashchange`, closes the mobile drawer after selection and
moves focus to the destination heading. Test real browser behavior before release.

Lucide's and Feather's upstream license notices are preserved under
`frontend/LUCIDE-LICENSE`. The production minimizer retains bundled
license comments inside `app.js` rather than generating an additional
un-deployed `app.js.LICENSE.txt` sidecar.

## Record interaction patterns (dialog enhancement)

- Mobile-first native `<details>` offers available record context with
  no JavaScript. With JS, the same escaped content opens in an accessible
  native `<dialog>` with focus restoration and Escape/Cancel.
- Owner and client provisioning forms are first rendered as real strict
  server-side forms; local JS upgrades them to modal sheets without
  cloning or modifying submitted fields or passwords/CSRF values.
- Destructive actions show a second confirmation step with the already
  authorized record label or exact IP. Always rely on server-side
  authorization and CSRF; no dialog is a security boundary.
- Preserve `dialog::backdrop`, TailAdmin tokens, 44px control heights,
  content wrapping at 320px, and dark/system/light modes.
- No simulated toasts for redirects: actual success/failure messaging
  requires a separately reviewed backend change to response semantics.

## Compact row-action menu & motion refinements

Both headers use an 80px block size so their bottom borders align.
Desktop and mobile list items now share a 44px vertical-ellipsis
options summary with accessible title and native browser hover tooltip.
The popover contains ordinary controls and native record details, so it
is **not** misrepresented as an ARIA menu. Forms keep their existing
strict field names and action endpoints, with no inline extra fields.
The menu is scrollable and flips above the final list items when useful.

Popover opening uses 130ms and native-dialog entrance uses 160ms;
hover effects are deliberately limited to color/background. The
`prefers-reduced-motion` media query suppresses animations/transitions.
Native `<dialog>` backdrops respond only to interactions outside the
actual content rectangle, including touch pointer input; clicking
within the dialog never dismisses it. Escape/Cancel restore focus.

An authorized successful action uses a fixed server-owned PRG route
belonging to that action rather than always showing Overview. Existing
form semantics, security gates and error statuses remain unchanged.

## Scrollable navigation and first-paint icons (November 2026)

The 80px Sidebar logo header stays fixed. A separate `flex: 1; min-height:
0; overflow-y: auto` region contains every navigation link **and** the
Sidebar footer. It supports keyboard navigation, trackpad, touch scrolling,
short viewport heights and independent scrolling from the main content.

All Lucide glyphs are now rendered in Twig as SVG references to a small,
locally hosted versioned `icons.svg` symbol sprite. This avoids JS-based
placeholder conversion on every server-side page transition. CSS supplies
`stroke: currentColor` and the icon sprite is preloaded in the document
head. No webfont file is bundled: the immediate system font stack is used,
so preloading a fictional Outfit font would be incorrect. The sprite,
application script, stylesheet and theme initialization are cacheable, with
versions based on their content SHA-256; protected HTML stays no-store.
