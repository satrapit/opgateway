/*
 * OPGateway admin interactions. The Alpine CSP build intentionally does not
 * require eval/Function; no user data or credentials are read by this bundle.
 */
import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;
Alpine.start();

const sidebar = document.querySelector('[data-sidebar]');
const openButton = document.querySelector('[data-sidebar-toggle]');
const closeButton = document.querySelector('[data-sidebar-close]');
const overlay = document.querySelector('[data-sidebar-overlay]');

if (sidebar && openButton && closeButton && overlay) {
  let restoreFocus = null;

  const setOpen = (open) => {
    sidebar.classList.toggle('-translate-x-full', !open);
    sidebar.classList.toggle('translate-x-0', open);
    overlay.hidden = !open;
    openButton.setAttribute('aria-expanded', String(open));
    if (open) {
      restoreFocus = document.activeElement;
      closeButton.focus();
    } else if (restoreFocus instanceof HTMLElement) {
      restoreFocus.focus();
      restoreFocus = null;
    }
  };

  openButton.addEventListener('click', () => setOpen(true));
  closeButton.addEventListener('click', () => setOpen(false));
  overlay.addEventListener('click', () => setOpen(false));
  sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    if (window.innerWidth < 1280) setOpen(false);
  }));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && openButton.getAttribute('aria-expanded') === 'true') {
      setOpen(false);
    }
    if (event.key === 'Tab' && openButton.getAttribute('aria-expanded') === 'true') {
      const focusable = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])'));
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  const desktop = window.matchMedia('(min-width: 1280px)');
  const onResize = () => {
    if (desktop.matches && openButton.getAttribute('aria-expanded') === 'true') setOpen(false);
  };
  if (desktop.addEventListener) desktop.addEventListener('change', onResize);
}

const themeSelect = document.querySelector('[data-theme-preference]');
if (themeSelect && window.OPGatewayTheme) {
  themeSelect.value = window.OPGatewayTheme.getPreference();
  themeSelect.addEventListener('change', () => {
    window.OPGatewayTheme.setPreference(themeSelect.value);
  });
}

// Native confirmation is keyboard-accessible and introduces no new sensitive
// modal state. Server-side CSRF, flags, authorization and audit still apply.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
