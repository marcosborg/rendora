import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { access, mkdtemp, rm, rename } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer } from 'node:net';
import { once } from 'node:events';
import { test } from 'node:test';

test('packaged public demo isolates roles, integrations and database generations', async t => {
  const mysql = Boolean(process.env.RENDORA_TRIAL_MYSQL_DSN);
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
  const startServer = useMysql => spawn('php', ['-S', `127.0.0.1:${port}`, '-t', publicDir, useMysql ? path.resolve('tests/mysql-demo-router.php') : path.join(publicDir, 'index.php')], {
    env: { ...process.env, APP_ENV: 'production', DEMO_MODE: 'false', COOKIE_SECURE: 'false', DB_PATH: path.join(tmp, 'main.sqlite'), ALLOW_LIVE_PAYMENTS: 'true' },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let server = startServer(mysql);
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
  async function waitForHealth() {
    for (let i = 0; i < 50; i++) {
      const health = await req('/health').catch(() => null);
      if (health?.ok) return health;
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    assert.fail(errors || 'Demo did not become healthy.');
  }
  const health = await waitForHealth();
  const healthData = await health.json();
  assert.equal(healthData.storage, mysql ? 'mysql' : 'sqlite');
  t.diagnostic(`${healthData.storage} HTTP ${healthData[healthData.storage + '_version']}`);
  const config=await (await req('/config')).json();
  assert.equal(config.public_demo, true);
  assert.equal(config.bank_transfers, false);
  assert.equal(config.paypal_sandbox, false);
  assert.equal((await req('/properties')).status, 401);
  const commercialUrl = `http://127.0.0.1:${port}/en/rental-management-software`;
  assert.doesNotMatch(await (await fetch(commercialUrl)).text(), /name="units"/);
  assert.equal((await fetch(commercialUrl, { method: 'POST' })).status, 403);
  for (const route of ['/legal', '/privacidade', '/cookies', '/termos', '/en/legal', '/en/privacy', '/en/cookies', '/en/terms']) {
    const response = await fetch(`http://127.0.0.1:${port}${route}`);
    assert.equal(response.status, 200, route);
    assert.equal(response.headers.get('x-robots-tag'), 'noindex');
    assert.match(await response.text(), /mailto:info@rendora.pt/);
  }
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
  const tenantApp=await req('/auth/demo',{method:'POST',body:{email:'morador@demo.homealone.pt',client:'resident-app'}});
  assert.equal(tenantApp.status,200);
  assert.equal(tenantApp.headers.get('set-cookie'),null);
  const {token}=await tenantApp.json();
  assert.match(token,/^resident_[a-f0-9]{64}$/);
  assert.equal((await req('/contracts',{headers:{Authorization:`Bearer ${token}`}})).status,200);
  assert.equal((await req('/tickets',{method:'POST',headers:{Authorization:`Bearer ${token}`},body:{}})).status,403);
  assert.equal((await req('/auth/demo',{method:'POST',body:{email:'gestor@demo.homealone.pt',client:'resident-app'}})).status,403);
  assert.equal((await req('/settings/bank-transfer', { session: manager })).status, 404);
  const owner = await login('proprietario@demo.homealone.pt', 'OWNER');
  const tech = await login('tecnico@demo.homealone.pt', 'TECHNICIAN');
  const porto = await login('gestor@demo.porto.pt', 'ADMIN', 'company-porto');
  const managerCleaning = await req('/cleaning', { session: manager });
  assert.equal(managerCleaning.status, 200);
  const cleaning = (await managerCleaning.json()).visits;
  assert.ok(cleaning.length > 0);
  assert.deepEqual((await (await req('/cleaning', { session: tech })).json()).visits, cleaning);
  assert.equal((await req('/cleaning', { session: resident })).status, 403);
  assert.equal((await req('/cleaning', { session: owner })).status, 403);
  assert.equal((await req('/cleaning/plans', { session: manager, method: 'POST', body: {} })).status, 403);
  assert.equal((await req(`/cleaning/plans/${cleaning[0].plan_id}/visits/${cleaning[0].occurrence_date}`, {
    session: tech, method: 'PATCH', body: { action: 'COMPLETE' },
  })).status, 403);

  for (const route of ['/properties', '/contacts', '/contracts', '/contracts/ct-ines/document', '/charges',
    '/expenses', '/tickets', '/leads', '/conversations', '/notifications', '/settings', '/members',
    '/audit', '/jobs', '/documents?entity_type=contract&entity_id=ct-ines']) {
    assert.equal((await req(route, { session: manager })).status, 200, route + '\n' + errors);
  }
  assert.equal((await req('/contracts/ct-miguel', { session: manager })).status, 200);
  assert.equal((await req('/contracts/ct-miguel', { session: resident })).status, 404);
  assert.equal((await req('/contracts/ct-ines', { session: resident })).status, 200);
  const ticketResponse = await req('/tickets/t-water', { session: resident });
  assert.equal(ticketResponse.status, 200, errors);
  const ticket = await ticketResponse.json();
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
  if (mysql) {
    const worker = async () => {
      const child = spawn('php', ['tests/contract-worker.php']);
      let stdout = '';
      let stderr = '';
      child.stdout.on('data', chunk => { stdout += chunk; });
      child.stderr.on('data', chunk => { stderr += chunk; });
      const [code] = await once(child, 'exit');
      assert.equal(code, 0, stderr);
      return Number(stdout);
    };
    assert.deepEqual((await Promise.all([worker(), worker()])).sort(), [200, 409]);

    const stopped = once(server, 'exit');
    server.kill();
    await stopped;
    server = startServer(false);
    assert.equal((await (await waitForHealth()).json()).storage, 'sqlite');
    assert.equal((await req('/auth/me', { session: resident })).status, 401);
    await login('morador@demo.homealone.pt', 'RESIDENT');
    return;
  }
  const next = 'demo-20260101000001.sqlite';
  snapshot(tmp, next);
  await rename(path.join(tmp, next), path.join(storage, next + '.upload'));
  assert.equal((await req('/auth/me', { session: resident })).status, 200);
  await rename(path.join(storage, next + '.upload'), path.join(storage, next));
  assert.equal((await req('/auth/me', { session: resident })).status, 401);
  await login('morador@demo.homealone.pt', 'RESIDENT');
  await assert.rejects(access(path.join(tmp, 'main.sqlite')), { code: 'ENOENT' });
});
