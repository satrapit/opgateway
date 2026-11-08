import '../css/app.css';
import Alpine from '@alpinejs/csp';

/**
 * TailAdmin Free interaction conventions with Alpine's CSP-safe evaluator.
 * This module never reads, stores or forwards application credentials.
 */
Alpine.data('adminShell', () => ({
  sidebarOpen: false,
  themeLabel: 'System',
  lastActivator: null,

  init() {
    this.refreshThemeLabel();
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener?.('change', () => this.applyTheme());
    document.addEventListener('keydown', (event) => {
      if (!this.sidebarOpen) return;
      if (event.key === 'Escape') { this.closeSidebar(); return; }
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

  openSidebar(event) {
    this.lastActivator = event?.currentTarget ?? null;
    this.sidebarOpen = true;
    document.body.classList.add('opg-drawer-open');
    this.$nextTick(() => this.$refs.sidebar?.focus());
  },
  closeSidebar() {
    if (!this.sidebarOpen) return;
    this.sidebarOpen = false;
    document.body.classList.remove('opg-drawer-open');
    this.$nextTick(() => this.lastActivator?.focus());
  },
  get sidebarClass() {
    return this.sidebarOpen ? 'is-open' : '';
  },
  get backdropVisible() {
    return this.sidebarOpen;
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
