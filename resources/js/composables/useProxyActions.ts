import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import proxyRoutes from '@/routes/proxies';

/**
 * The proxy Show page's mutations — pause, resume, delete, and ending a
 * signing-rotation overlap — with the busy flag each one needs while its
 * request is in flight.
 *
 * Every action takes an optional `onFinish`, called after the request
 * settles, so the caller can close whichever dialog it opened. The
 * composable owns no dialog state itself: which dialog is open is the page's
 * concern, not the request's.
 */
export function useProxyActions(teamSlug: Ref<string>, proxyId: number) {
    const pauseResumeBusy = ref(false);
    const deleteBusy = ref(false);
    const signingOverlapBusy = ref(false);
    const signingOverlapError = ref<string | null>(null);
    const validateBusyId = ref<number | null>(null);

    function routeArgs() {
        return { current_team: teamSlug.value, proxy: proxyId };
    }

    function pauseProxy(onFinish?: () => void): void {
        pauseResumeBusy.value = true;

        router.post(
            proxyRoutes.pause.store(routeArgs()).url,
            {},
            {
                onFinish: () => {
                    pauseResumeBusy.value = false;
                    onFinish?.();
                },
            },
        );
    }

    function resumeProxy(onFinish?: () => void): void {
        // AC10: resuming requires no confirmation.
        pauseResumeBusy.value = true;

        router.delete(proxyRoutes.pause.destroy(routeArgs()).url, {
            onFinish: () => {
                pauseResumeBusy.value = false;
                onFinish?.();
            },
        });
    }

    function deleteProxy(onFinish?: () => void): void {
        deleteBusy.value = true;

        router.delete(proxyRoutes.destroy(routeArgs()).url, {
            onFinish: () => {
                deleteBusy.value = false;
                onFinish?.();
            },
        });
    }

    function endSigningOverlap(onFinish?: () => void): void {
        signingOverlapBusy.value = true;
        signingOverlapError.value = null;

        router.delete(proxyRoutes.signing.overlap.destroy(routeArgs()).url, {
            preserveScroll: true,
            only: ['security'],
            onError: () => {
                signingOverlapError.value =
                    'Could not end the rotation overlap. Try again.';
            },
            onFinish: () => {
                signingOverlapBusy.value = false;
                onFinish?.();
            },
        });
    }

    /**
     * Send (or resend) a destination's validation challenge (T16; AC14, Flow
     * C) — immediate, no dialog. Busy state is per destination id, so one
     * row's in-flight send never disables another row's button. Success,
     * failure and the rate-limited line all arrive via the refreshed
     * `security` prop and the server's toast; the composable only owns the
     * request.
     */
    function validateDestination(
        destinationId: number,
        onFinish?: () => void,
    ): void {
        validateBusyId.value = destinationId;

        router.post(
            proxyRoutes.destinations.validate.store({
                ...routeArgs(),
                destination: destinationId,
            }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    validateBusyId.value = null;
                    onFinish?.();
                },
            },
        );
    }

    return {
        pauseResumeBusy,
        deleteBusy,
        signingOverlapBusy,
        signingOverlapError,
        validateBusyId,
        pauseProxy,
        resumeProxy,
        deleteProxy,
        endSigningOverlap,
        validateDestination,
    };
}
