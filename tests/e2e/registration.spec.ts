import { expect, test } from '@playwright/test';

import { applicationLink, latestMessageText } from './support/mailpit';
import { seedState } from './support/state';
import { uniqueName } from './support/unique';

// Signed-out specs: they exercise the way in, so they must not inherit the
// stored member session.
test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The whole way in for a new customer: register, prove the address, land on a
 * team of their own. The link is read out of Mailpit, so what this proves is
 * that the message a real user receives carries a link that works.
 */
test('a new user registers, verifies by email and reaches their own dashboard', async ({
    page,
    baseURL,
}) => {
    const email = `${uniqueName('e2e')
        .replace(/[^a-z0-9]/gi, '-')
        .toLowerCase()}@example.com`;
    const password = 'e2e-password';

    await page.goto('/register');
    await page.getByLabel('Name').fill('E2E Newcomer');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByTestId('register-user-button').click();

    // Registered but unverified: the team routes are closed until the address is
    // proven, so the dashboard bounces to the verification notice.
    await expect(
        page.getByRole('button', { name: /resend verification email/i }),
    ).toBeVisible();

    const link = applicationLink(
        await latestMessageText(email),
        baseURL ?? '',
        '/email/verify',
    );

    await page.goto(link);

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByTestId('team-switcher-trigger')).toContainText(
        "E2E Newcomer's Team",
    );
});

test('an unverified user cannot reach the dashboard', async ({ page }) => {
    const email = `${uniqueName('e2e')
        .replace(/[^a-z0-9]/gi, '-')
        .toLowerCase()}@example.com`;
    const password = 'e2e-password';

    await page.goto('/register');
    await page.getByLabel('Name').fill('E2E Unverified');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByTestId('register-user-button').click();

    await expect(
        page.getByRole('button', { name: /resend verification email/i }),
    ).toBeVisible();

    await page.goto('/e2e-worker-0-team/dashboard');

    await expect(
        page.getByRole('button', { name: /resend verification email/i }),
    ).toBeVisible();
});

test('registering with an email already in use is refused', async ({
    page,
}) => {
    const password = 'e2e-password';

    await page.goto('/register');
    await page.getByLabel('Name').fill('E2E Duplicate');
    await page.getByLabel('Email address').fill(seedState().signIn.email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByTestId('register-user-button').click();

    await expect(page.getByText(/already been taken/i)).toBeVisible();
    await expect(page).toHaveURL(/\/register/);
});
