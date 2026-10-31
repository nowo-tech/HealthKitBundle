import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — README screenshots in full demo context: masthead + live bundle output + Symfony web debug toolbar.
 * Captured as PNG; `make demo-screenshots` converts them to WebP and deletes the PNGs.
 */
test.describe.configure({ mode: 'serial' });

const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1440;

async function capture(page: Page, file: string, height: number): Promise<void> {
  await page.setViewportSize({ width: WIDTH, height });
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible();
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => setTimeout(done, 600))));
  await page.screenshot({ path: resolve(outDir, file) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Health Kit screenshots (full demo frame)', () => {
  test.use({ colorScheme: 'light' });

  test('readiness probes — table, public answer and liveness details', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('table.checks').getByRole('cell', { name: 'messenger.async', exact: true })).toBeVisible();
    await capture(page, 'health-overview.png', 1000);
  });

  test('metrics — token-guarded Prometheus scrape', async ({ page }) => {
    await page.goto('/');
    const scrape = page.locator('section.card').filter({ hasText: 'Scraper — GET /metrics' });
    await scrape.scrollIntoViewIfNeeded();
    await page.evaluate(() => {
      const el = Array.from(document.querySelectorAll('section.card')).find((c) => c.textContent?.includes('Scraper — GET /metrics'));
      if (el) {
        window.scrollTo(0, (el as HTMLElement).offsetTop - 70);
      }
    });
    await capture(page, 'metrics.png', 900);
  });
});
