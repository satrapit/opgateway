import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('Google is the first account login and registration action', () => {
  const login = read('templates/account/login.html.twig');
  const register = read('templates/account/register.html.twig');
  assert.ok(login.indexOf('Continue with Google') < login.indexOf('action="/account/login"'));
  assert.ok(register.indexOf('Continue with Google') < register.indexOf('action="/account/register"'));
  assert.match(login, /Use Google to sign in or create a password-optional account/);
  assert.doesNotMatch(login, /First connect your Google account/);
  assert.match(register, /if password_registration_available/);
});

test('account security controls retain labels, autocomplete and keyboard-native forms', () => {
  const security = read('templates/account/google.html.twig');
  const confirmation = read('templates/account/google-confirm.html.twig');
  const continuation = read('templates/account/google-continue.html.twig');
  assert.match(security, /<label[^>]+for="security-new-password"/);
  assert.match(security, /id="security-new-password"[^>]+autocomplete="new-password"/);
  assert.match(security, /method="post" action="\/account\/google\/unlink"/);
  assert.match(confirmation, /<button type="submit"/);
  assert.match(confirmation, /block referrer_policy %}no-referrer/);
  assert.match(continuation, /block referrer_policy %}no-referrer/);
  assert.match(continuation, /http-equiv="refresh"/);
  assert.match(continuation, /href="{{ authorization_url }}"/);
  assert.match(continuation, /If you are not redirected automatically/);
  assert.doesNotMatch(continuation, /<form/);
  assert.match(read('templates/account/reset-password.html.twig'), /block referrer_policy %}no-referrer/);
  assert.match(read('templates/account/verify.html.twig'), /block referrer_policy %}no-referrer/);
  assert.doesNotMatch(security, /tabindex="-1"/);
});

test('optional Google step-up remains usable without horizontal layout traps', () => {
  const clients = read('templates/account/sections/clients.html.twig');
  const provider = read('templates/account/sections/provider.html.twig');
  const css = read('frontend/src/css/app.css');
  assert.match(clients, /name="action" value="api_key_issue"/);
  assert.match(provider, /name="action" value="provider_credentials"/);
  assert.doesNotMatch(clients, /name="password"[^>]+required/);
  assert.doesNotMatch(provider, /name="current_password"[^>]+required/);
  assert.match(css, /\.opg-input/);
  assert.match(css, /\.opg-input \{ @apply block min-h-12/);
});
