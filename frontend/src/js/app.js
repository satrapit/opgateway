import '../css/app.css';
import Alpine from '@alpinejs/csp';
import {
  createIcons, Menu, X, Sun, Moon, Monitor, LayoutDashboard, UserCheck,
  Users, Server, Clock, ShieldCheck, ShieldX, ArrowUpRight, LogOut,
  Plus, Check, Ban,
} from 'lucide';

/**
 * CSP-compatible TailAdmin Free operator interactions.
 * Lucide icons are bundled into app.js; no runtime CDN, font or fetch.
 */
Alpine.data('adminShell', () => ({
  sidebarOpen: false,
  themeLabel: 'System',
  lastActivator: null,

  init() {
    this.refreshThemeLabel();
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener?.('change', () => this.applyTheme());

    const nav = this.$refs.sidebar?.querySelector('nav');
    if (nav) {
      this.updateActiveNavigation();
      nav.addEventListener('click', (event) => {
        const target = event.target;
        const link = target instanceof Element ? target.closest('a[href^="#"]') : null;
        if (!link || !nav.contains(link)) return;

        // Update immediately. Hashchange also keeps browser Back/Forward
        // and dashboard summary-card anchor navigation synchronized.
        this.updateActiveNavigation(link.hash);
        if (window.matchMedia('(max-width: 1023px)').matches) {
          this.closeSidebar(false);
          window.requestAnimationFrame(() => {
            const heading = document.getElementById(link.hash.slice(1))?.querySelector('h2');
            if (heading) {
              heading.setAttribute('tabindex', '-1');
              heading.focus({ preventScroll: true });
            }
          });
        }
      });
      window.addEventListener('hashchange', () => this.updateActiveNavigation());
    }

    document.addEventListener('keydown', (event) => {
      if (!this.sidebarOpen) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        this.closeSidebar();
        return;
      }
      if (event.key !== 'Tab') return;
      const items = Array.from(this.$refs.sidebar?.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled])',
      ) ?? []).filter((node) => node.getClientRects().length > 0);
      if (items.length === 0) return;
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    });
    document.addEventListener('submit', (event) => {
      const form = event.target.closest?.('form[data-confirm]');
      if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  },

  updateActiveNavigation(hash = window.location.hash) {
    const anchors = Array.from(this.$refs.sidebar?.querySelectorAll('nav a[href^="#"]') ?? []);
    if (anchors.length === 0) return;
    const selected = anchors.some((anchor) => anchor.hash === hash) ? hash : '#overview';
    for (const anchor of anchors) {
      if (anchor.hash === selected) anchor.setAttribute('aria-current', 'location');
      else anchor.removeAttribute('aria-current');
    }
  },

  openSidebar(event) {
    this.lastActivator = event?.currentTarget ?? null;
    this.sidebarOpen = true;
    document.body.classList.add('opg-drawer-open');
    this.$nextTick(() => this.$refs.sidebar?.focus());
  },
  closeSidebar(restoreFocus = true) {
    if (!this.sidebarOpen) return;
    this.sidebarOpen = false;
    document.body.classList.remove('opg-drawer-open');
    if (restoreFocus !== false) {
      this.$nextTick(() => this.lastActivator?.focus());
    }
  },
  get sidebarClass() {
    return this.sidebarOpen ? 'is-open' : '';
  },
  get backdropVisible() {
    return this.sidebarOpen;
  },
  get themeControlLabel() {
    return 'Color theme: ' + this.themeLabel + '. Activate to change';
  },
  cycleTheme() {
    const modes = ['system', 'light', 'dark'];
    const current = document.documentElement.dataset.theme || 'system';
    const next = modes[(modes.indexOf(current) + 1) % modes.length];
    try { window.localStorage.setItem('opgateway-theme', next); } catch (_) {}
    document.documentElement.dataset.theme = next;
    this.applyTheme();
    this.refreshThemeLabel();
  },
  applyTheme() {
    const choice = document.documentElement.dataset.theme || 'system';
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle(
      'dark', choice === 'dark' || (choice === 'system' && systemDark),
    );
  },
  refreshThemeLabel() {
    const chosen = document.documentElement.dataset.theme || 'system';
    this.themeLabel = chosen[0].toUpperCase() + chosen.slice(1);
  },
}));

Alpine.start();
createIcons({
  icons: {
    Menu, X, Sun, Moon, Monitor, LayoutDashboard, UserCheck, Users, Server,
    Clock, ShieldCheck, ShieldX, ArrowUpRight, LogOut, Plus, Check, Ban,
  },
  attrs: { 'stroke-width': 1.85 },
});
