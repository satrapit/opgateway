# TailAdmin Free-based design system

## Source and provenance

Visual reference: [TailAdmin Free](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template) and its [free demo](https://free-demo.tailadmin.com/). Audit on 2026-11-09: upstream `main/package.json` declares version **2.4.0**, Tailwind `^4.3.3`, Alpine `^3.17.2` and Webpack `^5.110.3`; upstream changelog describes a 2.5.0 release. Treat actual source and lockfile as authoritative, rather than assuming version parity with release notes.

TailAdmin Free is MIT licensed, copyright (c) 2023 TailAdmin. Retain notices for substantial copied parts. Never require Pro assets or runtime scripts.

## Tokens and usage

Use TailAdmin's original `brand-50..950` including `brand-500: #465fff`; `gray-50` surfaces, white cards, `gray-200` boundaries, `gray-900` dark surfaces, and the `shadow-theme-*` family. Sidebar/menu patterns use `menu-item`, active brand tint and compact iconography. Prefer `rounded-xl` / `rounded-2xl` cards, focused typography and restrained transitions. Never add decorative gradients or bespoke palettes.

## State patterns

Provide explicit informational empty states, a read-only mutation warning, controlled enabled actions, inline server errors, keyboard-friendly form labels, destructive confirmations and visible focus. Negative security responses should remain generic at the backend boundary. A form only renders if its actual existing route and authorization permit it; hidden UI is not authorization.

## Responsive & i18n

Mobile-first at 320, 375, 390, 768, 1024 and 1440px. Sidebar becomes a dismissible off-canvas drawer, with no document overflow; tables become cards or intentionally contained horizontal-scrolling regions on small screens. All mobile action targets should be around 44px. Use CSS logical properties and semantic ordering for future `dir=rtl` and translated labels. Initial text language remains English.

## Theme / CSP

Support `light`, `dark`, `system`; system preference uses `matchMedia('(prefers-color-scheme: dark)')`. Only the theme choice, never secret/session material, may be saved in localStorage. Theme initialization should run before first paint using same-origin files and a strict CSP. Use the CSP-compatible Alpine package or framework-independent JS; no `unsafe-eval`.

## Acceptance checklist

- Mobile and desktop without clipping/overflows; correct light, dark and system behavior.
- Keyboard focus and status announcements, contrast and `prefers-reduced-motion`.
- All data from the authorized, bounded server result, no fake chart/counter.
- No dead/unrouted navigation; no inactive operation portrayed as functional.
- No inline secrets, no runtime CDN, correct `no-store` and CSP headers.
- Negative CSRF/role/feature-flag tests, build validation and browser review actually run.
