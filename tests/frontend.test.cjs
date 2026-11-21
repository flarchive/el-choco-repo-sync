const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { runInNewContext } = require('node:vm');
const { webcrypto } = require('node:crypto');

function fixture(request) {
  const calls = [];
  const app = { translator: { trans: key => key }, initializers: { add() {} }, forum: { attribute: () => 'https://example.test/api' },
    request: async args => { calls.push(args); return request ? request(args) : { config: { enabled: false, actor: '', mappings: [], hasSecret: false, hasToken: false }, jobs: [], backups: [] }; } };
  const m = (tag, ...args) => ({ tag, args }); m.redraw = () => {};
  class ExtensionPage { oninit() {} }
  class Admin { page(value) { this.component = value; return this; } }
  const modules = { 'admin/app': app, 'admin/components/ExtensionPage': ExtensionPage, 'common/extenders/Admin': Admin };
  const component = { component: (...args) => ({ component: true, args }) };
  const output = { exports: {} };
  runInNewContext(readFileSync(join(__dirname, '../js/dist/admin.js'), 'utf8'), {
    module: output, m, crypto: webcrypto, window: { confirm: () => true },
    flarum: { reg: { get: (_, name) => modules[name] || component, add() {} } },
  });
  const page = new output.exports.RepoSyncPage(); page.oninit({ attrs: {} });
  return { page, calls, output };
}
const flush = () => new Promise(resolve => setImmediate(resolve));

test('Compiled admin extension registers the page and loads status without writing', async () => {
  const { page, calls, output } = fixture(); await flush();
  assert.equal(output.exports.extend[0].component, output.exports.RepoSyncPage);
  assert.equal(calls.length, 1); assert.equal(calls[0].method, 'GET');
  assert.equal(page.config.enabled, false);
  assert.equal(page.content().tag, '.container.RepoSync');
});

test('New mappings start disabled and use the protected section mode', async () => {
  const { page } = fixture(); await flush(); page.add();
  const row = page.config.mappings[0];
  assert.equal(row.enabled, false); assert.equal(row.mode, 'section');
  assert.equal(row.releases, false); assert.equal(row.file, 'README.md');
  assert.match(row.id, /^[\da-f-]{36}$/);
  assert.equal(page.dirty, true);
});

test('Unsaved settings cannot trigger synchronization or queue processing', async () => {
  const { page, calls } = fixture(); await flush(); page.add();
  await page.action('sync', { mapping: page.config.mappings[0].id }); await page.action('run');
  assert.equal(calls.length, 1);
});

test('Secure secret generation produces 256 random bits and is not saved automatically', async () => {
  const { page, calls } = fixture(); await flush();
  page.generateSecret(); const first = page.secret; page.generateSecret();
  assert.match(first, /^[a-f0-9]{64}$/); assert.notEqual(page.secret, first);
  assert.equal(calls.length, 1); assert.equal(page.dirty, true);
});

test('Successful saving clears secret and token from form memory', async () => {
  const { page, calls } = fixture(); await flush();
  page.secret = 'secret'; page.token = 'token'; page.dirty = true;
  await page.action('save', { secret: page.secret, token: page.token });
  assert.equal(page.secret, ''); assert.equal(page.token, ''); assert.equal(page.dirty, false);
  assert.equal(page.message, 'saved'); assert.equal(calls[1].body.action, 'save');
});

test('Failure keeps unsaved input and presents a sanitized error code', async () => {
  let writes = false;
  const { page } = fixture(args => {
    if (args.method === 'POST') { writes = true; throw { response: { error: 'invalid_config' } }; }
    return { config: { mappings: [] }, jobs: [], backups: [] };
  }); await flush(); page.dirty = true; page.secret = 'keep';
  await page.action('save');
  assert.equal(writes, true); assert.equal(page.secret, 'keep'); assert.equal(page.error, 'invalid_config');
  assert.equal(page.dirty, true); assert.equal(page.busy, false);
});

test('Removed page ignores late responses', async () => {
  let resolve;
  const { page } = fixture(() => new Promise(done => { resolve = done; }));
  page.onremove(); resolve({ config: { enabled: true }, jobs: [], backups: [] }); await flush();
  assert.equal(page.config, null);
});

test('Repository limit prevents unbounded forms', async () => {
  const { page } = fixture(); await flush(); for (let i = 0; i < 25; i++) page.add();
  assert.equal(page.config.mappings.length, 20);
});

test('First-post lookup updates the form without publishing or saving it', async () => {
  const { page, calls } = fixture(args => args.body?.action === 'resolve' ? { post: 42 } : { config: { mappings: [] }, jobs: [], backups: [] });
  await flush(); page.add(); const item = page.config.mappings[0]; item.discussion = '10';
  await page.resolvePost(item);
  assert.equal(item.post, '42'); assert.equal(page.dirty, true);
  assert.equal(calls[1].body.action, 'resolve');
});
