import { expect, test } from '@playwright/test';

import { uniqueName } from './support/unique';

// Signed-out specs: they exercise the way in, so they must not inherit the
// stored member session.
test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The whole way in for a new customer: register, get a team, land on its
 * dashboard. There is no email step to cover — `App\Models\User` does not
 * implement `MustVerifyEmail`, so registration signs the user straight in.
 */
test('a new user registers and lands on their own team dashboard', async ({
    page,
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

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByTestId('team-switcher-trigger')).toContainText(
        "E2E Newcomer's Team",
    );
});

test('registering with an email already in use is refused', async ({
    page,
}) => {
    const password = 'e2e-password';

    await page.goto('/register');
    await page.getByLabel('Name').fill('E2E Duplicate');
    await page.getByLabel('Email address').fill('e2e@example.com');
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByTestId('register-user-button').click();

    await expect(page.getByText(/already been taken/i)).toBeVisible();
    await expect(page).toHaveURL(/\/register/);
});
