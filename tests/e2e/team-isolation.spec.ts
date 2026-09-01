import { expect, test } from './support/fixtures';
import { seedState } from './support/state';

/**
 * Team scoping is the product's hardest boundary: a proxy holds an ingest token
 * and destination credentials, so a member of one team reaching another team's
 * proxy is the worst failure this application has. Feature tests cover the
 * scope itself; this asserts the browser cannot get there either.
 */
test('another team\'s proxy is neither listed nor reachable by URL', async ({ page, account }) => {
    const { outsider, foreignProxy } = seedState();

    await page.goto(`/${account.teamSlug}/proxies`);
    await expect(page.getByRole('link', { name: foreignProxy.name })).toHaveCount(0);

    // Under the foreign team's own slug — membership is what fails here.
    const foreign = await page.goto(`/${outsider.teamSlug}/proxies/${foreignProxy.id}`);
    expect(foreign?.status()).toBeGreaterThanOrEqual(400);

    // And under the member's own slug — the team scope is what fails here.
    const scoped = await page.goto(`/${account.teamSlug}/proxies/${foreignProxy.id}`);
    expect(scoped?.status()).toBeGreaterThanOrEqual(400);
});
