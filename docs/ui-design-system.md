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
