import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeFormSubmissionLock } from '../src/js/form-submissions.js';

function setup() {
  const docHandlers = new Map();
  const windowHandlers = new Map();
  class Control {
    constructor(name = '', value = '') {
      this.name = name;
      this.value = value;
      this.disabled = false;
      this.type = 'submit';
    }
  }
  class Input extends Control {
    remove() { this.removed = true; }
  }
  class Form {
    constructor(controls = []) {
      this.controls = controls;
      this.dataset = {};
      this.attributes = new Map();
      this.appended = [];
      this.method = 'post';
    }
    querySelectorAll() { return this.controls; }
    append(item) { this.appended.push(item); }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
  }
  globalThis.HTMLFormElement = Form;
  globalThis.HTMLInputElement = Input;
  globalThis.document = {
    addEventListener: (name, listener) => docHandlers.set(name, listener),
    createElement: (name) => { assert.equal(name, 'input'); return new Input(); },
  };
  globalThis.window = {
    addEventListener: (name, listener) => windowHandlers.set(name, listener),
  };
  initializeFormSubmissionLock();
  const submit = (form, submitter, defaultPrevented = false) => {
    const event = {
      target: form, submitter, defaultPrevented,
      preventDefault() { this.defaultPrevented = true; },
    };
    docHandlers.get('submit')(event);
    return event;
  };
  return { Form, Control, Input, submit, restore: () => windowHandlers.get('pageshow')() };
}

test('submit locks buttons instantly but preserves named submitter values', () => {
  const { Form, Control, submit } = setup();
  const save = new Control('action', 'save');
  const revoke = new Control('action', 'revoke');
  const form = new Form([save, revoke]);
  const event = submit(form, save);
  assert.equal(event.defaultPrevented, false);
  assert.equal(save.disabled, true);
  assert.equal(revoke.disabled, true);
  assert.equal(form.dataset.opgSubmitting, 'true');
  assert.equal(form.attributes.get('aria-busy'), 'true');
  assert.equal(form.appended.length, 1);
  assert.deepEqual([form.appended[0].type, form.appended[0].name, form.appended[0].value],
    ['hidden', 'action', 'save']);
  assert.equal(submit(form, revoke).defaultPrevented, true);
  assert.equal(form.appended.length, 1);
});

test('canceled confirmation cannot lock the form', () => {
  const { Form, Control, submit } = setup();
  const button = new Control();
  const form = new Form([button]);
  submit(form, button, true);
  assert.equal(button.disabled, false);
  assert.equal(form.dataset.opgSubmitting, undefined);
  submit(form, button);
  assert.equal(button.disabled, true);
});

test('pageshow restores only modified buttons and removes temporary fields', () => {
  const { Form, Control, submit, restore } = setup();
  const chosen = new Control('decision', 'approve');
  const alreadyDisabled = new Control('not-active', '1');
  alreadyDisabled.disabled = true;
  const form = new Form([chosen, alreadyDisabled]);
  submit(form, chosen);
  restore();
  assert.equal(chosen.disabled, false);
  assert.equal(alreadyDisabled.disabled, true);
  assert.equal(form.dataset.opgSubmitting, undefined);
  assert.equal(form.attributes.has('aria-busy'), false);
  assert.equal(form.appended[0].removed, true);
  assert.equal(submit(form, chosen).defaultPrevented, false);
});

test('external submitter is disabled and dialog-only forms are not locked', () => {
  const { Form, Control, submit } = setup();
  const external = new Control();
  const form = new Form([]);
  submit(form, external);
  assert.equal(external.disabled, true);
  const dialog = new Form([new Control()]);
  dialog.method = 'dialog';
  submit(dialog, dialog.controls[0]);
  assert.equal(dialog.controls[0].disabled, false);
});
