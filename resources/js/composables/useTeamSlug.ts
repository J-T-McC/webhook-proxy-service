import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * The current team's slug, as every team-scoped route argument needs it.
 *
 * Falls back to `''` when no team is in page context — the same fallback
 * these pages already carried individually. Not usable inside a page's
 * `defineOptions({ layout })` callback, which runs outside the component
 * instance and so has no `usePage()` context; breadcrumbs take `currentTeam`
 * from their own callback argument instead (see `@/lib/breadcrumbs`).
 */
export function useTeamSlug(): ComputedRef<string> {
    const page = usePage();

    return computed(() => page.props.currentTeam?.slug ?? '');
}
