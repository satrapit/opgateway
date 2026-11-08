/* Same-origin blocking head script: apply theme before CSS paints. */
(function () {
  'use strict';
  const key = 'opgateway-theme';
  const allowed = ['system', 'light', 'dark'];
  let preference = 'system';

  try {
    const stored = localStorage.getItem(key);
    if (allowed.includes(stored)) preference = stored;
  } catch (_) {
    // Browsers that block storage still follow the OS theme.
  }

  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const apply = () => {
    const dark = preference === 'dark' || (preference === 'system' && media.matches);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.setAttribute('data-color-scheme', dark ? 'dark' : 'light');
  };
  apply();

  const onSystemChange = () => { if (preference === 'system') apply(); };
  if (media.addEventListener) media.addEventListener('change', onSystemChange);
  else if (media.addListener) media.addListener(onSystemChange);

  window.OPGatewayTheme = {
    getPreference: () => preference,
    setPreference: (next) => {
      if (!allowed.includes(next)) return;
      preference = next;
      try { localStorage.setItem(key, next); } catch (_) { /* Storage optional. */ }
      apply();
    },
  };
})();
