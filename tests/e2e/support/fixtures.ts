import { existsSync } from 'node:fs';
import { join } from 'node:path';

import { test as base } from '@playwright/test';

import { signIn } from './auth';
import { AUTH_DIR, seedState, type SeededAccount } from './state';

/**
 * A signed-in test, with one seeded account and one session per parallel
 * worker.
 *
 * Sharing a single session across workers looks like it should work and does
 * not: the session cookie identifies one server-side session, and Inertia's
 * validation errors live in that session's flash. Two workers submitting forms
 * at the same time consume each other's flash, and a form comes back with the
 * fields it was rejected for and no error on any of them.
 */
export const test = base.extend<
    { account: SeededAccount },
    { workerAccount: SeededAccount; workerStorageState: string }
>({
    workerAccount: [
        // eslint-disable-next-line no-empty-pattern
        async ({}, use, workerInfo) => {
            const { workers } = seedState();
            const account = workers[workerInfo.parallelIndex % workers.length];

            await use(account);
        },
        { scope: 'worker' },
    ],

    workerStorageState: [
        async ({ browser, workerAccount }, use, workerInfo) => {
            const file = join(AUTH_DIR, `worker-${workerInfo.parallelIndex}.json`);

            if (!existsSync(file)) {
                // A page made straight from the browser inherits none of the
                // project's `use` options, baseURL included.
                const page = await browser.newPage({
                    baseURL: workerInfo.project.use.baseURL,
                    storageState: undefined,
                });

                await signIn(page, workerAccount);
                await page.context().storageState({ path: file });
                await page.close();
            }

            await use(file);
        },
        { scope: 'worker' },
    ],

    storageState: ({ workerStorageState }, use) => use(workerStorageState),

    account: async ({ workerAccount }, use) => {
        await use(workerAccount);
    },
});

export { expect } from '@playwright/test';
