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
    // Show progress only on the button that initiated the submission.
    // Other submit buttons are disabled to avoid conflicting actions.
    const active = submitter && 'disabled' in submitter
      ? submitter : Array.from(controls).find((control) => !control.disabled) ?? null;
    let progress = null;
    if (active) {
      const originalLabel = active.getAttribute('aria-label');
      const originalBusy = active.getAttribute('aria-busy');
      const isTextInput = active instanceof HTMLInputElement && active.type === 'submit';
      const originalValue = isTextInput ? active.value : null;
      if (isTextInput) {
        // Generated CSS pseudo-elements do not render on input elements.
        active.value = 'Please wait…';
      } else {
        const description = originalLabel || active.textContent?.trim() || '';
        active.setAttribute('aria-label', description
          ? description + ' — Processing' : 'Processing');
      }
      active.setAttribute('aria-busy', 'true');
      active.dataset.opgLoading = 'true';
      progress = { active, originalLabel, originalBusy, originalValue };
    }

    const changed = [];
    for (const control of controls) {
      if (control.disabled) continue;
      control.disabled = true;
      changed.push(control);
    }
    form.dataset.opgSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');
    pending.set(form, { changed, replacement, progress });
  });

  // A Back/Forward Cache restore must not leave previously submitted forms
  // disabled forever. Only restore controls that this handler disabled.
  window.addEventListener('pageshow', () => {
    for (const [form, { changed, replacement, progress }] of pending) {
      for (const control of changed) control.disabled = false;
      replacement?.remove();
      if (progress) {
        const { active, originalLabel, originalBusy, originalValue } = progress;
        if (originalValue !== null) active.value = originalValue;
        if (originalLabel === null) active.removeAttribute('aria-label');
        else active.setAttribute('aria-label', originalLabel);
        if (originalBusy === null) active.removeAttribute('aria-busy');
        else active.setAttribute('aria-busy', originalBusy);
        delete active.dataset.opgLoading;
      }
      delete form.dataset.opgSubmitting;
      form.removeAttribute('aria-busy');
    }
    pending.clear();
  });
}
