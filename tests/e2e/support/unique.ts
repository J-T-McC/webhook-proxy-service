/**
 * Specs share one database and run in parallel workers, so every record a spec
 * creates carries a suffix no other run can collide with.
 */
export function uniqueName(prefix: string): string {
    return `${prefix} ${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
}
