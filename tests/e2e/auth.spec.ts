import { expect, test } from '@playwright/test';

import { signIn, signOut } from './support/auth';
import { seedState } from './support/state';

// Signed-out specs: they exercise the way in, so they must not inherit the
// stored member session. Fortify throttles login attempts per email, so these
// run in order and sign in as few times as the assertions allow.
test.use({ storageState: { cookies: [], origins: [] } });
test.describe.configure({ mode: 'serial' });

test('a member signs in, reaches their team and signs back out', async ({ page }) => {
    const account = seedState().signIn;

    await signIn(page, account);
    await expect(page.getByTestId('team-switcher-trigger')).toContainText('E2E Sign In Team');

    await signOut(page);

    await page.goto(`/${account.teamSlug}/dashboard`);
    await expect(page).toHaveURL(/\/login/);
});

test('a wrong password is rejected and no session is granted', async ({ page }) => {
    const account = seedState().signIn;

    await page.goto('/login');
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill('not-the-password');
    await page.getByTestId('login-button').click();

    await expect(page.getByText(/credentials do not match/i)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);

    // The dashboard must still be closed, not merely un-navigated-to.
    await page.goto(`/${account.teamSlug}/dashboard`);
    await expect(page).toHaveURL(/\/login/);
});
