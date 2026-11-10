/**
 * Accessible progressive enhancement for native <details> action menus.
 *
 * No JavaScript is required to open a row's options. This only ensures
 * that at most one menu remains open and adds outside-click / Escape dismissal.
 * Do not add role="menu": it contains real forms and nested <details>,
 * rather than an ARIA menu's menuitem controls.
 */
export function initializeRowMenus() {
  const menus = Array.from(document.querySelectorAll('details[data-opg-row-menu]'));
  if (menus.length === 0) return;

  const closeOthers = (keep = null) => {
    for (const menu of menus) {
      if (menu !== keep && menu.open) menu.open = false;
    }
  };

  for (const menu of menus) {
    menu.addEventListener('toggle', () => {
      if (!menu.open) {
        menu.classList.remove('opg-open-up', 'opg-open-down');
        return;
      }
      closeOthers(menu);
      const trigger = menu.querySelector('summary');
      const popover = menu.querySelector('.opg-action-popover');
      if (!trigger || !popover) return;
      const rect = trigger.getBoundingClientRect();
      const below = window.innerHeight - rect.bottom - 12;
      const above = rect.top - 12;
      const needed = Math.min(popover.scrollHeight, window.innerHeight * 0.65);
      const preferUp = below < needed && above > below;
      menu.classList.toggle('opg-open-up', preferUp);
      menu.classList.toggle('opg-open-down', !preferUp);
    });
  }

  document.addEventListener('pointerdown', (event) => {
    // Dialogs need to restore focus to their originating row actions.
    if (document.querySelector('dialog[open]')) return;
    if (event.target instanceof Element
      && event.target.closest('[data-opg-row-menu]')) return;
    closeOthers();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || document.querySelector('dialog[open]')) return;
    const open = menus.find((menu) => menu.open);
    if (!open) return;
    event.preventDefault();
    event.stopPropagation();
    open.open = false;
    open.querySelector('summary')?.focus();
  });
}
