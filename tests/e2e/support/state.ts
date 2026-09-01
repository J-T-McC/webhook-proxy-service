import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export type SeededAccount = {
    email: string;
    teamSlug: string;
};

export type SeedState = {
    password: string;
    member: SeededAccount;
    signIn: SeededAccount;
    outsider: SeededAccount;
    foreignProxy: { id: number; name: string };
};

const e2eDir = dirname(fileURLToPath(import.meta.url)).replace(/\/support$/, '');

export const STATE_FILE = join(e2eDir, '.state.json');

/** Session saved by `auth.setup.ts` and reused by the signed-in specs. */
export const MEMBER_STORAGE_STATE = join(e2eDir, '.auth', 'member.json');

let cached: SeedState | null = null;

/** What `artisan e2e:seed` created for this run, written by the global setup. */
export function seedState(): SeedState {
    cached ??= JSON.parse(readFileSync(STATE_FILE, 'utf8')) as SeedState;

    return cached;
}
