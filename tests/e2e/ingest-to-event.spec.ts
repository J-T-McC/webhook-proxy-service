import { expect, test } from '@playwright/test';

import { createProxy } from './support/proxies';
import { uniqueName } from './support/unique';

/**
 * The product's reason to exist: a webhook posted to a proxy's ingest URL is
 * captured, and a member can find it, read what arrived and replay it.
 */
test('a webhook posted to the ingest URL is captured, readable and replayable', async ({
    page,
    request,
}) => {
    const name = uniqueName('E2E Ingest');
    const marker = uniqueName('marker').replace(/\s/g, '-');

    await createProxy(page, name);
    const proxyUrl = page.url();

    const ingestUrl = await page.getByTestId('ingest-url').locator('input').inputValue();
    expect(ingestUrl).toContain('/ingest/');

    // `EnsureIngestIsSecure` rejects a plaintext ingest request. The suite runs
    // over HTTP, so it presents the header a TLS-terminating load balancer sets
    // in production, which the app already trusts (bootstrap/app.php).
    const ingestResponse = await request.post(ingestUrl, {
        headers: { 'content-type': 'application/json', 'x-forwarded-proto': 'https' },
        data: { event: 'e2e.captured', marker },
    });
    expect(ingestResponse.status()).toBeLessThan(300);

    // Straight to the proxy's unfiltered event list — the Events button on the
    // proxy page carries the destination filter it was viewing, which excludes
    // an event that has no delivery row yet.
    await page.goto(`${proxyUrl}/events`);

    const firstEvent = page.getByRole('row').nth(1);
    await expect(firstEvent).toBeVisible();
    await firstEvent.getByRole('link', { name: 'View' }).click();

    // The payload is masked until asked for — reading it is a deliberate act.
    await page.getByRole('button', { name: 'Reveal payload' }).click();
    await expect(page.getByText(marker)).toBeVisible();

    await page.getByRole('button', { name: 'Replay' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
});
