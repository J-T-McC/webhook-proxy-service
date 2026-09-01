import { expect, type Page } from '@playwright/test';

import { seedState, type SeededAccount } from './state';

/**
 * Signs in through the login form — the specs never inject a session, because
 * whether the form itself works is part of what this layer proves.
 */
export async function signIn(
    page: Page,
    account: SeededAccount = seedState().member,
    password: string = seedState().password,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByTestId('login-button').click();

    await expect(page).toHaveURL(new RegExp(`/${account.teamSlug}/dashboard`));
}

/** Logging out lands on the marketing home page, not the login form. */
export async function signOut(page: Page): Promise<void> {
    await page.getByTestId('sidebar-menu-button').click();
    await page.getByTestId('logout-button').click();

    await expect(page.getByRole('link', { name: 'Log in' }).first()).toBeVisible();
}
