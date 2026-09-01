import { expect, test } from '@playwright/test';

import { createProxy } from './support/proxies';
import { seedState } from './support/state';
import { uniqueName } from './support/unique';

test('a proxy can be created, listed, renamed, paused, resumed and deleted', async ({ page }) => {
    const { member } = seedState();
    const name = uniqueName('E2E Proxy');
    const renamed = `${name} renamed`;

    await createProxy(page, name);

    await page.goto(`/${member.teamSlug}/proxies`);
    await expect(page.getByRole('link', { name })).toBeVisible();

    await page.getByRole('link', { name }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Edit' }).click();
    await page.locator('#name').fill(renamed);
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('heading', { name: renamed, exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Pause' }).click();
    await page.getByRole('button', { name: 'Pause proxy' }).click();
    await expect(page.getByRole('button', { name: 'Resume' })).toBeVisible();

    await page.getByRole('button', { name: 'Resume' }).click();
    await expect(page.getByRole('button', { name: 'Pause' })).toBeVisible();

    await page.getByRole('button', { name: `Delete proxy ${renamed}` }).click();
    await page.getByRole('button', { name: 'Delete proxy', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/${member.teamSlug}/proxies$`));
    await expect(page.getByRole('link', { name: renamed })).toHaveCount(0);
});

test('a destination URL that is not https is refused with the error on the field', async ({
    page,
}) => {
    const { member } = seedState();

    await page.goto(`/${member.teamSlug}/proxies/create`);
    await page.locator('#name').fill(uniqueName('E2E Invalid'));
    await page.locator('#destination-0-url').fill('http://example.com/webhook');
    const submission = page.waitForResponse((response) => response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create proxy' }).click();
    await submission;

    await expect(page.getByText(/must be a valid URL/i)).toBeVisible();
    await expect(page).toHaveURL(/\/proxies\/create/);
});
