import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — happy path: the demo page renders the live bundle output, and the raw endpoints keep their
 * public contract (status only for anonymous callers, details for token holders, fail-closed /metrics).
 */
const DETAILS_TOKEN = 'demo-details-token-change-me';
const METRICS_TOKEN = 'demo-metrics-token-change-me';

test.describe('Health Kit demo', () => {
  test('overview shows every readiness probe with its status', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Health checks and Prometheus metrics' })).toBeVisible();

    const table = page.locator('table.checks');
    for (const probe of ['doctrine.default', 'redis', 'messenger.async', 'frankenphp', 'disk']) {
      await expect(table.getByRole('cell', { name: probe, exact: true })).toBeVisible();
    }
    // 12 seeded messages vs warn_at 10 → degraded, non-fatal.
    const messenger = table.getByRole('row').filter({ hasText: 'messenger.async' });
    await expect(messenger).toContainText('degraded');
    await expect(page.getByText('overall: degraded')).toBeVisible();
    await expect(page.locator('pre').filter({ hasText: 'health_probe_status{probe="redis"} 1' })).toBeVisible();
  });

  test('public callers only get the status', async ({ request }) => {
    const ready = await request.get('/health/ready', { headers: { 'X-Forwarded-For': '127.0.0.1' } });
    expect(ready.status()).toBe(200);
    expect(ready.headers()['cache-control']).toContain('no-store');
    const body = await ready.json();
    // The e2e browser reaches the container through the Docker bridge (not loopback inside the container).
    expect(Object.keys(body)).toEqual(['status']);
    expect(body.status).toBe('degraded');
  });

  test('token callers get probe details', async ({ request }) => {
    const ready = await request.get('/health/ready', { headers: { Authorization: `Bearer ${DETAILS_TOKEN}` } });
    expect(ready.status()).toBe(200);
    const body = await ready.json();
    expect(body.checks['doctrine.default'].status).toBe('ok');
    expect(body.checks['redis'].status).toBe('ok');
    expect(body.checks['messenger.async'].data.messages).toBe(12);
    expect(body.checks['frankenphp'].data.frankenphp_mode).toBe('worker');

    const query = await request.get(`/health/ready?token=${DETAILS_TOKEN}`);
    expect(Object.keys(await query.json())).toEqual(['status']);
  });

  test('metrics are token-guarded Prometheus text', async ({ request }) => {
    const denied = await request.get('/metrics');
    expect(denied.status()).toBe(401);
    expect(denied.headers()['www-authenticate']).toBe('Bearer realm="metrics"');

    const scrape = await request.get('/metrics', { headers: { Authorization: `Bearer ${METRICS_TOKEN}` } });
    expect(scrape.status()).toBe(200);
    expect(scrape.headers()['content-type']).toBe('text/plain; version=0.0.4; charset=utf-8');
    const text = await scrape.text();
    expect(text).toContain('# TYPE health_probe_status gauge');
    expect(text).toContain('health_probe_data{probe="messenger.async",key="messages"} 12');
    expect(text).toMatch(/demo_home_views_total \d+/);
  });
});
