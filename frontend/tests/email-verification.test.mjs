import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeEmailVerification } from '../src/js/email-verification.js';

test('verification POST is submitted once from a visible browser page', () => {
  let submissions = 0;
  class Form {
    constructor() { this.dataset = {}; }
    requestSubmit() { submissions++; }
  }
  const form = new Form();
  globalThis.HTMLFormElement = Form;
  globalThis.document = {
    visibilityState: 'visible',
    querySelector: () => form,
  };
  globalThis.window = { setTimeout: (callback) => callback() };

  initializeEmailVerification();
  assert.equal(submissions, 1);
});

test('verification does not auto-submit a hidden or already-pending page', () => {
  let submissions = 0;
  class Form {
    constructor() { this.dataset = {}; }
    requestSubmit() { submissions++; }
  }
  const form = new Form();
  globalThis.HTMLFormElement = Form;
  globalThis.document = {
    visibilityState: 'hidden',
    querySelector: () => form,
  };
  globalThis.window = { setTimeout: (callback) => callback() };
  initializeEmailVerification();

  document.visibilityState = 'visible';
  form.dataset.opgSubmitting = 'true';
  initializeEmailVerification();
  assert.equal(submissions, 0);
});
