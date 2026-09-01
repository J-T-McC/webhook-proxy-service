import { test as setup } from '@playwright/test';

import { signIn } from './support/auth';
import { MEMBER_STORAGE_STATE } from './support/state';

/**
 * Signs the member in once and saves the session for every spec that is not
 * itself about signing in. Fortify throttles login attempts per email, so a
 * worker-per-spec sign-in would start locking the account out as the suite
 * grows.
 */
setup('authenticate as the seeded member', async ({ page }) => {
    await signIn(page);

    await page.context().storageState({ path: MEMBER_STORAGE_STATE });
});
