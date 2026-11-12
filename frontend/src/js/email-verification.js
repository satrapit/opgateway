/**
 * Preserve a non-mutating verification-link GET while keeping the normal
 * browser experience one-click. The server-rendered POST remains usable when
 * JavaScript is unavailable; scanners that only prefetch the GET cannot
 * consume the single-use token.
 */
export function initializeEmailVerification() {
  const form = document.querySelector(
    'form[data-opg-auto-submit="email-verification"]',
  );
  if (!(form instanceof HTMLFormElement)
    || typeof form.requestSubmit !== 'function') return;

  window.setTimeout(() => {
    if (document.visibilityState === 'hidden' || form.dataset.opgSubmitting === 'true') return;
    form.requestSubmit();
  }, 0);
}
