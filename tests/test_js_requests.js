/**
 * Runs the real admin.js against a stub DOM and captures the requests it
 * actually sends.
 *
 * This is the seam the other tests do not cover. The server was verified over
 * real HTTP and the helpers were verified in isolation, but the bug that
 * locked the site out tonight lived exactly here: the page sent "0" where the
 * server expected an absent value, and nothing either side tested noticed,
 * because each side was correct on its own terms.
 */

const fs = require('fs');
const vm = require('vm');

const JS = '/Users/surajitroy/Local Sites/morecalculators-dev/app/public/wp-content/plugins/restorepilot-backup-migration/assets/js/admin.js';

let pass = 0, fail = 0;
const failures = [];
function check(label, ok) {
  if (ok) { pass++; console.log('PASS  ' + label); }
  else { fail++; failures.push(label); console.log('FAIL  ' + label); }
}

// ── Stub DOM ───────────────────────────────────────────────────────────────
const elements = new Map();
function makeEl(id) {
  const listeners = {};
  const el = {
    id, value: '', checked: false, textContent: '', hidden: false, disabled: false,
    style: {}, classList: { add(){}, remove(){}, contains(){ return false; } },
    dataset: {}, attributes: {}, files: [],
    addEventListener(t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
    removeEventListener() {},
    dispatch(t, ev) { (listeners[t] || []).forEach(fn => fn.call(el, ev || { target: el, preventDefault(){} })); },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(el.attributes, k) ? el.attributes[k] : null; },
    setAttribute(k, v) { el.attributes[k] = String(v); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    scrollIntoView() {}, focus() {}, submit() {}, requestSubmit() {},
    closest() { return null; }, appendChild() {}, removeChild() {},
    parentNode: { removeChild() {} },
  };
  return el;
}
function el(id) {
  if (!elements.has(id)) elements.set(id, makeEl(id));
  return elements.get(id);
}

const document = {
  getElementById: (id) => elements.has(id) ? elements.get(id) : null,
  querySelector: () => null,
  querySelectorAll: () => [],
  addEventListener: () => {},
  createElement: () => makeEl('created'),
  body: makeEl('body'),
};

// Only the ids the restore flow needs actually exist.
[
  'rp-restore-form', 'rp-restore-confirm-modal', 'rp-restore-confirm-cancel',
  'rp-restore-confirm-continue', 'rp-restore-confirm-check', 'rp-restore-confirm-new-admin',
  'rp_create_new_admin_hidden', 'rp_new_admin_email_hidden',
  'rp-new-admin-fields', 'rp-new-admin-email-input', 'rp-new-admin-password-input',
  'rp-new-admin-error', 'rp-restore-progress', 'rp-restore-progress-bar',
  'rp-restore-progress-pct', 'rp-restore-progress-text', 'rp-restore-progress-label',
].forEach(el);

const restoreForm = el('rp-restore-form');
restoreForm.querySelectorAll = () => [];
restoreForm.querySelector = () => null;

// ── Capture every request ──────────────────────────────────────────────────
const sent = [];
function toObject(fd) {
  const o = {};
  if (fd && typeof fd.forEach === 'function') fd.forEach((v, k) => { o[k] = v; });
  return o;
}
const fetchImpl = (url, opts) => {
  const body = toObject(opts && opts.body);
  sent.push(body);
  const action = body.action;
  let payload = { success: true, data: {} };
  if (action === 'restorepilot_ajax_restore') {
    payload = { success: true, data: { job_id: 'JOB123', poll_token: 'TOKEN456', message: 'queued' } };
  } else if (action === 'restorepilot_set_restore_admin_password') {
    payload = { success: true, data: { email: 'chosen@example.test', message: 'set' } };
  }
  return Promise.resolve({
    json: () => Promise.resolve(payload),
    text: () => Promise.resolve(JSON.stringify(payload)),
  });
};

const sandbox = {
  document,
  window: {
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    sessionStorage: { getItem: () => '0', setItem() {} },
    location: { href: '', reload() {} },
    setTimeout: (fn) => fn,
    setInterval: () => 1,
    clearInterval: () => {},
    addEventListener: () => {},
    FormData,
  },
  fetch: fetchImpl,
  FormData,
  ajaxurl: '/wp-admin/admin-ajax.php',
  restorePilotData: {
    nonce: 'NONCE',
    restoreTabUrl: 'http://site/wp-admin/admin.php?page=restorepilot&tab=restore',
    loginUrl: 'http://site/wp-login.php?redirect_to=plugin',
    i18n: new Proxy({}, { get: (t, k) => String(k) }),
  },
  console,
  Date, Math, JSON, parseInt, parseFloat, String, Number, Boolean, Array, Object, RegExp, Error,
  setTimeout: (fn) => fn, setInterval: () => 1, clearInterval: () => {},
  alert: () => {}, confirm: () => true,
};
sandbox.window.document = document;
sandbox.self = sandbox;
sandbox.globalThis = sandbox;

vm.createContext(sandbox);
try {
  vm.runInContext(fs.readFileSync(JS, 'utf8'), sandbox, { filename: 'admin.js' });
} catch (e) {
  console.log('FAIL  admin.js threw while loading: ' + e.message);
  process.exit(1);
}
check('admin.js loads without throwing', true);

// ── Scenario: operator ticks the box and fills both fields ────────────────
el('rp-restore-confirm-new-admin').checked = true;
el('rp-restore-confirm-new-admin').dispatch('change');
check('Ticking the box reveals the fields', el('rp-new-admin-fields').hidden === false);

el('rp-new-admin-email-input').value = 'chosen@example.test';
el('rp-new-admin-password-input').value = 'MyChosen-Passw0rd!';
el('rp-restore-confirm-continue').dispatch('click');

check('Hidden create-new-admin flag is "1", not "0"', el('rp_create_new_admin_hidden').value === '1');
check('Chosen email is copied into the form', el('rp_new_admin_email_hidden').value === 'chosen@example.test');
check('SECURITY: the password field is cleared from the DOM after capture',
  el('rp-new-admin-password-input').value === '');

// The submit handler is what triggers queueBackgroundRestore.
restoreForm.dispatch('submit', { preventDefault() {}, target: restoreForm });

// ── What actually went to the server ──────────────────────────────────────
const queue = sent.find(r => r.action === 'restorepilot_ajax_restore');
check('A restore request was sent', !!queue);
if (queue) {
  check('SECURITY: the restore request carries NO password',
    !Object.keys(queue).some(k => /password/i.test(k) && k !== 'new_admin_custom_password')
    && !Object.values(queue).some(v => String(v).includes('MyChosen-Passw0rd!')));
  check('The restore request carries no username field (none is collected)',
    !('new_admin_username' in queue));
}

// ── The password call, once the restore reports complete ──────────────────
sent.length = 0;
// Drive the poller the way a completed job would.
const pollBody = { action: 'restorepilot_restore_status' };
// The completion path runs inside pollRestoreJob; simulate by invoking the
// same fetch shape the page would receive.
const completed = {
  success: true,
  data: {
    status: 'complete', phase: 'complete', progress: 100,
    message: 'Restore completed.',
    new_admin_awaiting_password: true,
    new_admin_email: 'chosen@example.test',
  },
};
sandbox.fetch = (url, opts) => {
  const body = toObject(opts && opts.body);
  sent.push(body);
  if (body.action === 'restorepilot_restore_status') {
    return Promise.resolve({ json: () => Promise.resolve(completed), text: () => Promise.resolve(JSON.stringify(completed)) });
  }
  return Promise.resolve({
    json: () => Promise.resolve({ success: true, data: { email: 'chosen@example.test' } }),
    text: () => Promise.resolve('{}'),
  });
};

if (typeof sandbox.window.restorepilotQueueRestoreForm === 'function') {
  sandbox.window.restorepilotQueueRestoreForm(restoreForm);
}

setTimeout(() => {
  const pwCall = sent.find(r => r.action === 'restorepilot_set_restore_admin_password');
  check('The page sends the password call once the restore completes', !!pwCall);
  if (pwCall) {
    check('It carries the chosen password', pwCall.new_password === 'MyChosen-Passw0rd!');
    check('It authenticates with the job id and poll token',
      !!pwCall.job_id && !!pwCall.poll_token);
  }

  console.log('');
  if (fail === 0) console.log('ALL ' + pass + ' CHECKS PASSED');
  else console.log(fail + ' FAILURE(S): ' + failures.join('; '));
  process.exit(fail === 0 ? 0 : 1);
}, 300);
