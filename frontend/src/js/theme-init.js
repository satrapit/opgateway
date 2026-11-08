/*
 * Early, local, synchronous color preference. No secrets stored, no remote
 * dependencies and no inline scripts. Loaded before CSS to avoid theme flash.
 */
(function () {
  'use strict';
  let choice = 'system';
  try {
    const stored = window.localStorage.getItem('opgateway-theme');
    if (stored === 'light' || stored === 'dark') choice = stored;
  } catch (_) {
    // Storage may be disabled; system preference still works.
  }
  const systemDark = window.matchMedia
    && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const dark = choice === 'dark' || (choice === 'system' && systemDark);
  document.documentElement.classList.toggle('dark', Boolean(dark));
  document.documentElement.dataset.theme = choice;
})();
