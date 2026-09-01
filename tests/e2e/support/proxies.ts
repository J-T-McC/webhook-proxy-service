import { expect, type Page } from '@playwright/test';

import { type SeededAccount } from './state';

/**
 * Creates a proxy through the form and leaves the browser on its page. The
 * destination is a URL nothing answers — it stays unvalidated, so the consent
 * gate (#18) skips it and no delivery leaves the machine running the suite.
 */
export async function createProxy(
    page: Page,
    account: SeededAccount,
    name: string,
    destinationUrl = 'https://example.com/webhook',
): Promise<void> {
    await page.goto(`/${account.teamSlug}/proxies/create`);
    await page.locator('#name').fill(name);
    await page.locator('#destination-0-url').fill(destinationUrl);
    await page.getByRole('button', { name: 'Create proxy' }).click();

    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
}
