import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

const POLL_INTERVAL_MS = 5000;

/**
 * The 5-second Inertia partial-reload poll with an on/off toggle, shared by
 * every page that needs "keep this list current while I'm looking at it"
 * (`proxies/events/Index.vue`, `events/Index.vue`). The on/off preference is
 * per tab (`sessionStorage`), not per account — a viewing choice, not a
 * setting. Skips a tick while the tab is hidden, or while `isPaused` (an
 * open dialog, say) says refreshing would pull the rug out from under the
 * user's in-progress action.
 *
 * @param storageKey  Distinct per page, so each page remembers its own
 *   on/off choice rather than sharing one across unrelated pages.
 * @param only        The Inertia partial-reload prop list (`router.reload`'s
 *   `only` option).
 * @param isPaused    An extra, page-specific reason to skip a tick (e.g. a
 *   dialog open over the list) — evaluated fresh on every tick.
 */
export function useAutoRefreshPolling(
    storageKey: string,
    only: string[],
    isPaused: () => boolean = () => false,
): { pollingEnabled: Ref<boolean>; togglePolling: () => void } {
    const pollingEnabled = ref(
        typeof sessionStorage === 'undefined' ||
            sessionStorage.getItem(storageKey) !== 'off',
    );

    function togglePolling(): void {
        pollingEnabled.value = !pollingEnabled.value;

        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem(
                storageKey,
                pollingEnabled.value ? 'on' : 'off',
            );
        }
    }

    let pollTimer: ReturnType<typeof setInterval> | null = null;

    function poll(): void {
        if (!pollingEnabled.value || document.hidden || isPaused()) {
            return;
        }

        router.reload({ only });
    }

    onMounted(() => {
        pollTimer = setInterval(poll, POLL_INTERVAL_MS);
    });

    onBeforeUnmount(() => {
        if (pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    return { pollingEnabled, togglePolling };
}
