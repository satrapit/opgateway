/**
 * CSP-safe, no-dependency, progressive enhancement for the administrator UI.
 *
 * Native <details> and real POST forms remain functional if JavaScript is off
 * or HTMLDialogElement.showModal is unsupported. No fetch/AJAX and no changes
 * to form action, method, field names, CSRF or existing server redirects.
 * Record content is cloned from Twig-escaped DOM; never use innerHTML.
 */
export function initializeAdminDialogs(renderLocalIcons = () => {}) {
  const recordDialog = document.getElementById('opg-record-dialog');
  const provisionDialog = document.getElementById('opg-provision-dialog');
  const confirmDialog = document.getElementById('opg-confirm-dialog');
  const supported = typeof HTMLDialogElement !== 'undefined'
    && typeof HTMLDialogElement.prototype.showModal === 'function';

  // Native details/forms and a browser confirmation remain safe fallbacks.
  if (!supported || !recordDialog || !provisionDialog || !confirmDialog) {
    document.addEventListener('submit', (event) => {
      const form = event.target instanceof HTMLFormElement ? event.target : null;
      if (form?.matches('[data-confirm]') && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
      }
    });
    return;
  }

  const closeButtons = document.querySelectorAll('[data-opg-close-dialog]');
  for (const button of closeButtons) {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  }

  // Clicking the actual modal backdrop closes it. Avoid closing on clicks
  // inside dialog padding/content and ignore pointer drags across the edge.
  for (const dialog of [recordDialog, provisionDialog, confirmDialog]) {
    let startedOutside = false;
    const outside = (event) => {
      const rect = dialog.getBoundingClientRect();
      return event.target === dialog && (
        event.clientX < rect.left || event.clientX > rect.right
        || event.clientY < rect.top || event.clientY > rect.bottom
      );
    };
    dialog.addEventListener('pointerdown', (event) => {
      startedOutside = outside(event);
    });
    dialog.addEventListener('pointerup', (event) => {
      if (startedOutside && outside(event)) dialog.close();
      startedOutside = false;
    });
    dialog.addEventListener('pointercancel', () => { startedOutside = false; });
  }

  // Details: the native summary is still usable without JS. With JS, the
  // server-escaped existing definition list is cloned into a focused dialog.
  let recordActivator = null;
  recordDialog.addEventListener('close', () => {
    recordActivator?.focus();
    recordActivator = null;
    document.getElementById('opg-record-dialog-body')?.replaceChildren();
  });
  for (const details of document.querySelectorAll('[data-opg-record-details]')) {
    const summary = details.querySelector('summary');
    const fields = details.querySelector('dl');
    if (!summary || !fields) continue;
    summary.addEventListener('click', (event) => {
      event.preventDefault();
      recordActivator = summary;
      document.getElementById('opg-record-dialog-title').textContent =
        details.dataset.recordHeading || 'Record details';
      document.getElementById('opg-record-dialog-body')
        .replaceChildren(fields.cloneNode(true));
      recordDialog.showModal();
      recordDialog.querySelector('[data-opg-close-dialog]')?.focus();
    });
  }

  // Provisioning: replace the existing inline form with a launcher only after
  // JS is ready. Move the *same* form node into the dialog, never clone its
  // CSRF or inject new posted fields. With JS disabled it stays inline.
  let provisionActivator = null;
  let activeProvisionForm = null;
  provisionDialog.addEventListener('close', () => {
    activeProvisionForm?.remove();
    activeProvisionForm?.querySelector('label')?.classList.add('opg-sr-only');
    activeProvisionForm?.classList.remove('opg-dialog-form');
    activeProvisionForm = null;
    provisionActivator?.focus();
    provisionActivator = null;
  });
  for (const form of document.querySelectorAll('form[data-opg-provision]')) {
    const submitButton = form.querySelector('button[type="submit"]');
    const input = form.querySelector('input[name="value"]');
    if (!submitButton || !input) continue;

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'opg-secondary-button opg-modal-launcher';
    const icon = document.createElement('i');
    icon.dataset.lucide = 'plus';
    icon.className = 'opg-icon';
    icon.setAttribute('aria-hidden', 'true');
    trigger.append(icon, document.createTextNode(submitButton.textContent.trim()));
    trigger.setAttribute('aria-label', form.dataset.opgDialogTitle || 'Open request form');
    trigger.addEventListener('click', () => {
      provisionActivator = trigger;
      activeProvisionForm = form;
      document.getElementById('opg-provision-dialog-title').textContent =
        form.dataset.opgDialogTitle || 'New request';

      // These are fixed local UI strings, not user-supplied or server-derived
      // response content; no credentials are read or logged.
      const kind = form.querySelector('input[name="action"]')?.value;
      document.getElementById('opg-provision-dialog-description').textContent =
        kind === 'create_client'
          ? 'The new client is created disabled. Activate it separately after review.'
          : 'Enter one exact IPv4 or IPv6 address. Approval is a separate step.';
      form.classList.add('opg-dialog-form');
      form.querySelector('label')?.classList.remove('opg-sr-only');
      document.getElementById('opg-provision-dialog-body').replaceChildren(form);
      provisionDialog.showModal();
      // The original form was detached during the initial icon pass. Render
      // its local Lucide markup only after reattaching it to the dialog.
      renderLocalIcons();
      input.focus();
    });
    form.replaceWith(trigger);
  }

  // Destructive review forms: intercepted once, with an explicit confirmation
  // step. requestSubmit() retains native form validity and submit semantics.
  let pendingForm = null;
  let pendingSubmitter = null;
  let confirmActivator = null;
  let approvedResubmit = null;
  confirmDialog.addEventListener('close', () => {
    confirmActivator?.focus();
    confirmActivator = null;
    pendingForm = null;
    pendingSubmitter = null;
  });
  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || !form.matches('form[data-confirm]')) return;
    if (approvedResubmit === form) {
      approvedResubmit = null;
      return;
    }
    event.preventDefault();
    pendingForm = form;
    pendingSubmitter = event.submitter || form.querySelector('button[type="submit"]');
    confirmActivator = pendingSubmitter;
    document.getElementById('opg-confirm-dialog-description').textContent =
      form.dataset.confirm || 'Confirm this access change?';
    document.getElementById('opg-confirm-dialog-target').textContent =
      form.dataset.confirmTarget || 'Selected record';
    confirmDialog.showModal();
    confirmDialog.querySelector('[data-opg-confirm-submit]')?.focus();
  });
  confirmDialog.querySelector('[data-opg-confirm-submit]')?.addEventListener('click', () => {
    const form = pendingForm;
    const submitter = pendingSubmitter;
    if (!form) return;
    confirmDialog.close();
    approvedResubmit = form;
    // requestSubmit dispatches the native submit event, validating the form.
    if (submitter instanceof HTMLElement && submitter.isConnected) {
      form.requestSubmit(submitter);
    } else {
      form.requestSubmit();
    }
    // A form blocked by browser constraints must be confirmed again next time.
    approvedResubmit = null;
  });
}
