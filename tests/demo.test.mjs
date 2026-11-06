import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { access, mkdtemp, rm, rename } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer } from 'node:net';
import { once } from 'node:events';
import { test } from 'node:test';

test('packaged public demo isolates roles, integrations and database generations', async t => {
  const tmp = await mkdtemp(path.join(tmpdir(), 'rendora-demo-'));
  const packaged = execFileSync('node', ['scripts/package.mjs', '--demo'], { encoding: 'utf8' }).trim();
  t.after(async () => {
    await rm(tmp, { recursive: true, force: true });
    await rm(packaged, { recursive: true, force: true });
  });
  const publicDir = path.join(packaged, 'demo_public');
  const storage = path.join(packaged, 'rendora-demo', 'storage');
  const snapshot = (dir, name) => execFileSync('php', ['bin/demo-snapshot.php', path.join(dir, name)], { encoding: 'utf8' }).trim();
  t.diagnostic(snapshot(storage, 'demo-20260101000000.sqlite'));

  const socket = createServer().listen(0, '127.0.0.1');
  await once(socket, 'listening');
  const { port } = socket.address();
  await new Promise(resolve => socket.close(resolve));
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', publicDir, path.join(publicDir, 'index.php')], {
    env: { ...process.env, APP_ENV: 'production', DEMO_MODE: 'false', COOKIE_SECURE: 'false', DB_PATH: path.join(tmp, 'main.sqlite'), ALLOW_LIVE_PAYMENTS: 'true' },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let errors = '';
  server.stderr.on('data', chunk => { errors += chunk; });
  t.after(() => server.kill());

  const req = (route, { session, company = 'company-lisbon', method = 'GET', body, headers } = {}) =>
    fetch(`http://127.0.0.1:${port}/api/v1${route}`, {
      method, headers: {
        'X-Company-Id': company,
        ...(session && { Cookie: session.cookie, 'X-CSRF-Token': session.csrf }),
        ...(body && { 'Content-Type': 'application/json', Origin: 'https://demo.rendora.pt' }),
        ...headers,
      }, body: body && JSON.stringify(body),
    });
  let health;
  for (let i = 0; i < 50; i++) {
    health = await req('/health').catch(() => null);
    if (health) break;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert.equal(health?.status, 200, errors);
  t.diagnostic(`SQLite HTTP ${(await health.json()).sqlite_version}`);
  assert.equal((await (await req('/config')).json()).public_demo, true);
  assert.equal((await req('/properties')).status, 401);
  for (const slug of ['casa-lisboa', 'porto-living']) assert.equal((await req('/public/' + slug)).status, 200);

  async function login(email, role, company = 'company-lisbon') {
    const response = await req('/auth/demo', { method: 'POST', body: { email } });
    assert.equal(response.status, 200, errors);
    const header = response.headers.get('set-cookie');
    assert.match(header, /^__Host-rendora_demo=[a-f0-9]+; Path=\/; HttpOnly; SameSite=Strict; Max-Age=86400; Secure$/);
    const data = await response.json();
    assert.equal(data.user.platform_admin, false);
    const session = { cookie: header.split(';')[0], csrf: data.csrf };
    const me = await (await req('/auth/me', { session })).json();
    assert.deepEqual(me.companies.map(c => [c.id, c.role]), [[company, role]]);
    assert.equal((await req('/dashboard', { session, company })).status, 200, errors);
    assert.equal((await req('/platform', { session })).status, 403);
    return session;
  }

  const manager = await login('gestor@demo.homealone.pt', 'ADMIN');
  const resident = await login('morador@demo.homealone.pt', 'RESIDENT');
  const owner = await login('proprietario@demo.homealone.pt', 'OWNER');
  const tech = await login('tecnico@demo.homealone.pt', 'TECHNICIAN');
  const porto = await login('gestor@demo.porto.pt', 'ADMIN', 'company-porto');
  for (const route of ['/properties', '/contacts', '/contracts', '/contracts/ct-ines/document', '/charges',
    '/expenses', '/tickets', '/leads', '/conversations', '/notifications', '/settings', '/members',
    '/audit', '/jobs', '/documents?entity_type=contract&entity_id=ct-ines']) {
    assert.equal((await req(route, { session: manager })).status, 200, route + '\n' + errors);
  }
  assert.equal((await req('/contracts/ct-miguel', { session: manager })).status, 200);
  assert.equal((await req('/contracts/ct-miguel', { session: resident })).status, 404);
  assert.equal((await req('/contracts/ct-ines', { session: resident })).status, 200);
  const ticket = await (await req('/tickets/t-water', { session: resident })).json();
  assert.ok(ticket.notes.every(note => !note.internal));
  assert.equal((await req('/reports/export', { session: owner })).status, 200);
  assert.equal((await req('/reports/export', { session: resident })).status, 403);
  assert.equal((await req('/tickets/t-water', { session: tech })).status, 200);
  assert.equal((await req('/tickets/t-light', { session: tech })).status, 404);
  assert.equal((await req('/properties', { session: porto })).status, 403);
  assert.equal((await req('/properties', { session: manager, company: 'company-porto' })).status, 403);
  for (const route of ['/auth/register', '/auth/login', '/auth/reset', '/auth/forgot', '/platform/companies',
    '/webhooks/stripe', '/webhooks/billing', '/settings/billing', '/settings/connect', '/charges/test/checkout',
    '/payments/test/refund', '/members', '/documents', '/properties', '/public/casa-lisboa/leads']) {
    assert.equal((await req(route, { method: 'POST', session: manager, body: {} })).status, 403, route);
  }
  assert.equal((await req('/auth/me', { headers: { Cookie: resident.cookie.replace('__Host-rendora_demo', 'ha_session') } })).status, 401);
  assert.equal((await req('/auth/logout', { method: 'POST', session: tech })).status, 200);
  assert.equal((await req('/auth/me', { session: tech })).status, 401);
  const next = 'demo-20260101000001.sqlite';
  snapshot(tmp, next);
  await rename(path.join(tmp, next), path.join(storage, next + '.upload'));
  assert.equal((await req('/auth/me', { session: resident })).status, 200);
  await rename(path.join(storage, next + '.upload'), path.join(storage, next));
  assert.equal((await req('/auth/me', { session: resident })).status, 401);
  await login('morador@demo.homealone.pt', 'RESIDENT');
  await assert.rejects(access(path.join(tmp, 'main.sqlite')), { code: 'ENOENT' });
});
