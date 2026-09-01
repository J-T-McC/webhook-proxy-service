import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export type SeededAccount = {
    email: string;
    teamSlug: string;
};

export type SeedState = {
    password: string;
    workers: SeededAccount[];
    signIn: SeededAccount;
    rejected: SeededAccount;
    outsider: SeededAccount;
    foreignProxy: { id: number; name: string };
};

const e2eDir = dirname(fileURLToPath(import.meta.url)).replace(/\/support$/, '');

export const STATE_FILE = join(e2eDir, '.state.json');

/** Where each worker's saved session lives (see `fixtures.ts`). */
export const AUTH_DIR = join(e2eDir, '.auth');

let cached: SeedState | null = null;

/** What `artisan e2e:seed` created for this run, written by the global setup. */
export function seedState(): SeedState {
    cached ??= JSON.parse(readFileSync(STATE_FILE, 'utf8')) as SeedState;

    return cached;
}
