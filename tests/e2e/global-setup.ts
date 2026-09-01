import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';

import type { FullConfig } from '@playwright/test';

import { AUTH_DIR, STATE_FILE } from './support/state';
import type { SeedState } from './support/state';

/**
 * Seeds the fixed accounts once per run and writes what they are to disk for
 * the specs to read. The artisan binary differs by environment: locally the
 * database only exists inside Sail, in CI it is a service container the runner
 * talks to directly.
 */
export default function globalSetup(config: FullConfig): void {
    const command = (
        process.env.E2E_ARTISAN ?? './vendor/bin/sail artisan'
    ).split(' ');
    const artisan = (args: string[]): string =>
        execFileSync(command[0], [...command.slice(1), ...args], {
            encoding: 'utf8',
        });

    assertAppUrlMatches(artisan, config.projects[0]?.use.baseURL ?? '');

    const output = artisan(['e2e:seed', '--json']);
    const json = output.slice(output.indexOf('{'), output.lastIndexOf('}') + 1);
    const state = JSON.parse(json) as SeedState;

    writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));

    // Sessions from an earlier run point at rows a re-seed may have replaced.
    rmSync(AUTH_DIR, { recursive: true, force: true });
    mkdirSync(AUTH_DIR, { recursive: true });
}

/**
 * Signed links, emailed links and the ingest URL shown in the UI are all built
 * from APP_URL, never from the request host. If APP_URL names a different
 * origin than the specs browse, those links point somewhere the browser is not,
 * and the failures that follow look like application bugs. Fail here instead,
 * where the cause is obvious.
 */
function assertAppUrlMatches(
    artisan: (args: string[]) => string,
    baseURL: string,
): void {
    const appUrl = artisan([
        'tinker',
        '--execute=echo config("app.url");',
    ]).trim();

    if (new URL(appUrl).origin !== new URL(baseURL).origin) {
        throw new Error(
            `APP_URL is ${appUrl} but the specs browse ${baseURL}. ` +
                'Set APP_URL to the origin the app is actually served on, or set E2E_BASE_URL to match APP_URL.',
        );
    }
}
