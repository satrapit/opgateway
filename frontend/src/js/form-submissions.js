/**
 * Prevent accidental repeated submissions in both Admin and Account.
 * Runs after confirmation handlers, so a canceled confirmation never locks a
 * form. Native validation fires before submit; invalid forms remain editable.
 *
 * Submitters with a name/value need a hidden replacement because disabled
 * controls are omitted from form submissions. Keep the server's action/CSRF
 * fields exactly as they would have been sent without this enhancement.
 */
export function initializeFormSubmissionLock() {
  const pending = new Map();
  const submitSelector = 'button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]';

  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || form.method.toLowerCase() === 'dialog') return;
    if (pending.has(form)) {
      event.preventDefault();
      return;
    }
    // The administrator confirmation dialog intentionally cancels the first
    // submit event; only its approved requestSubmit() may lock the form.
    if (event.defaultPrevented) return;

    const submitter = event.submitter;
    let replacement = null;
    if (submitter?.name
      && !(submitter instanceof HTMLInputElement && submitter.type === 'image')) {
      replacement = document.createElement('input');
      replacement.type = 'hidden';
      replacement.name = submitter.name;
      replacement.value = submitter.value;
      form.append(replacement);
    }

    const controls = new Set(form.querySelectorAll(submitSelector));
    // HTML permits submit buttons associated via form="id" outside the form.
    if (submitter && 'disabled' in submitter) controls.add(submitter);
    const changed = [];
    for (const control of controls) {
      if (control.disabled) continue;
      control.disabled = true;
      changed.push(control);
    }
    form.dataset.opgSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');
    pending.set(form, { changed, replacement });
  });

  // A Back/Forward Cache restore must not leave previously submitted forms
  // disabled forever. Only restore controls that this handler disabled.
  window.addEventListener('pageshow', () => {
    for (const [form, { changed, replacement }] of pending) {
      for (const control of changed) control.disabled = false;
      replacement?.remove();
      delete form.dataset.opgSubmitting;
      form.removeAttribute('aria-busy');
    }
    pending.clear();
  });
}
