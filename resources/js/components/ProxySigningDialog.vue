<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import CopyField from '@/components/CopyField.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import type { ProxySecurity } from '@/types/proxies';

/**
 * The Manage proxy signing dialog (Screen 6; Flows G, H, I) — scoped to the
 * proxy, never to one destination (Amendment B ruling 1). Modelled on
 * `ReplayDialog.vue`'s shape (plain `Dialog`, nothing here is destructive).
 *
 * Five states, driven by `props.signing` (T38's status-only sub-object) plus
 * two pieces of in-session-only state this component owns: the freshly
 * generated secret (`revealedSecret`, never persisted anywhere but this
 * component's own memory, cleared the moment the dialog closes — AC57) and
 * whether signing has been disabled at least once this session
 * (`everDisabledThisSession` — `SecretStore::disable()` deletes every row,
 * so the server has no way to distinguish "never enabled" from "disabled
 * after being enabled"; T38's own completion notes call this out).
 */
const props = defineProps<{
    open: boolean;
    teamSlug: string;
    proxyId: number;
    proxyName: string;
    signing: ProxySecurity['signing'];
    /**
     * design-10 § Interactions, "Permission gating on the Show page" — every
     * state-changing action inside this dialog (Enable signing, Regenerate
     * signing secret, Disable signing, End overlap now) is gated on the same
     * `canUpdate` computed `Show.vue` already uses for its other mutating
     * controls. Every trigger that opens this dialog is itself
     * `canUpdate`-gated already, so this guard has no live exposure today —
     * it closes the gap for the dialog's own actions regardless of how it
     * is reached.
     */
    canUpdate: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const revealedSecret = ref<string | null>(null);
const everDisabledThisSession = ref(false);
const pendingAction = ref<
    'enable' | 'regenerate' | 'disable' | 'endOverlap' | null
>(null);
const busy = computed(() => pendingAction.value !== null);
const requestError = ref<string | null>(null);

type DialogState = 'not-enabled' | 'reveal' | 'enabled' | 'overlap';

const state = computed<DialogState>(() => {
    if (revealedSecret.value !== null) {
        return 'reveal';
    }

    if (!props.signing.enabled) {
        return 'not-enabled';
    }

    return props.signing.overlap_expires_at ? 'overlap' : 'enabled';
});

// Re-opening always starts from whatever `security.signing` currently says —
// never re-shows a secret from a previous open (AC57).
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            revealedSecret.value = null;
            requestError.value = null;
            pendingAction.value = null;
        }
    },
);

/**
 * **Done** is the sole keyboard-or-pointer-reachable exit from the one-time
 * reveal sub-state (design-gate ruling 4) — `Esc`/overlay-click/the
 * corner `X` are all suppressed for the duration of that sub-state only.
 * This handler is the outermost guard: even if some other path tried to
 * close the dialog while revealing, the close is refused here.
 */
function handleOpenChange(value: boolean): void {
    if (!value && state.value === 'reveal') {
        return;
    }

    emit('update:open', value);
}

/** Belt-and-braces alongside `handleOpenChange` — blocks the two Reka UI
 * dismissal paths (`Esc`, overlay click) that fire before `update:open`
 * would even be reached, so nothing flashes/animates before being refused. */
function suppressDismissalDuringReveal(event: {
    preventDefault: () => void;
}): void {
    if (state.value === 'reveal') {
        event.preventDefault();
    }
}

const doneButtonRef = useTemplateRef('doneButtonRef');

watch(state, (value) => {
    if (value === 'reveal') {
        nextTick(() => {
            doneButtonRef.value?.$el?.focus();
        });
    }
});

/**
 * `ProxySigningController@store` is deliberately the only JSON-returning
 * endpoint this whole feature has (T37) — it carries the secret's one-time
 * plaintext, which must never enter an Inertia prop or the session store.
 * Routed through a raw `fetch()` (the same escape hatch `PayloadViewer.vue`
 * already established for this app's one other non-Inertia endpoint) rather
 * than Inertia's own `router.post()`, which expects an Inertia-shaped
 * response and would error on a plain JSON body.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function generate(action: 'enable' | 'regenerate'): Promise<void> {
    pendingAction.value = action;
    requestError.value = null;

    try {
        const response = await fetch(
            proxyRoutes.signing.store({
                current_team: props.teamSlug,
                proxy: props.proxyId,
            }).url,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!response.ok) {
            requestError.value =
                action === 'enable'
                    ? 'Could not enable signing. Try again.'
                    : 'Could not regenerate the signing secret. Try again.';

            return;
        }

        const body = (await response.json()) as { secret: string };
        revealedSecret.value = body.secret;

        // The card/dialog's own "enabled"/"generated at" status is a
        // separate Inertia prop (`security`, T38) this JSON response never
        // carries — refresh it in the background so the dialog's next
        // non-reveal render (after Done) already reflects the new status.
        // An unhandled failure here would leave `props.signing` stale for
        // the life of the page — e.g. `overlap_expires_at` staying `null`
        // after a successful regenerate — so a failed refresh is surfaced
        // the same way every other request failure in this dialog is
        // (`requestError` + `AlertError`), rather than discarded silently.
        router.reload({
            only: ['security'],
            onError: () => {
                requestError.value =
                    "Signing secret generated, but this proxy's status could not be refreshed. Close and reopen this dialog to see the current status.";
            },
        });
    } catch {
        requestError.value =
            action === 'enable'
                ? 'Could not enable signing. Try again.'
                : 'Could not regenerate the signing secret. Try again.';
    } finally {
        pendingAction.value = null;
    }
}

function handleDone(): void {
    emit('update:open', false);
}

function handleDisable(): void {
    pendingAction.value = 'disable';
    requestError.value = null;

    router.delete(
        proxyRoutes.signing.destroy({
            current_team: props.teamSlug,
            proxy: props.proxyId,
        }).url,
        {
            preserveScroll: true,
            only: ['security'],
            onSuccess: () => {
                everDisabledThisSession.value = true;
                emit('update:open', false);
            },
            onError: () => {
                requestError.value = 'Could not disable signing. Try again.';
            },
            onFinish: () => {
                pendingAction.value = null;
            },
        },
    );
}

function handleEndOverlap(): void {
    pendingAction.value = 'endOverlap';
    requestError.value = null;

    router.delete(
        proxyRoutes.signing.overlap.destroy({
            current_team: props.teamSlug,
            proxy: props.proxyId,
        }).url,
        {
            preserveScroll: true,
            only: ['security'],
            onError: () => {
                requestError.value =
                    'Could not end the rotation overlap. Try again.';
            },
            onFinish: () => {
                pendingAction.value = null;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent
            :show-close-button="state !== 'reveal'"
            @escape-key-down="suppressDismissalDuringReveal"
            @pointer-down-outside="suppressDismissalDuringReveal"
        >
            <DialogHeader>
                <DialogTitle>Signing for {{ props.proxyName }}</DialogTitle>
                <DialogDescription>
                    Lets every destination this proxy dispatches to verify that
                    a dispatch really came from this proxy, using the Standard
                    Webhooks specification's signature format. One secret is
                    used for all of this proxy's destinations, including any
                    added later.
                </DialogDescription>
            </DialogHeader>

            <template v-if="state === 'not-enabled'">
                <p class="text-sm">
                    This proxy does not sign its dispatches yet.
                </p>
                <p
                    v-if="everDisabledThisSession"
                    class="text-sm text-muted-foreground"
                >
                    Enabling again generates a new secret — your previous one is
                    never shown or reused.
                </p>
            </template>

            <template v-else-if="state === 'reveal'">
                <Alert
                    class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100"
                >
                    <AlertTitle class="text-blue-900 dark:text-blue-100">
                        Copy this now
                    </AlertTitle>
                    <AlertDescription class="text-blue-900 dark:text-blue-100">
                        This is the only time this secret will ever be shown.
                        Configure every destination's receiver with it before
                        you close this dialog — the product cannot show it to
                        you again.
                    </AlertDescription>
                </Alert>
                <CopyField
                    :value="revealedSecret ?? ''"
                    copy-label="Copy signing secret"
                    announcement="Signing secret copied to clipboard"
                />
            </template>

            <template v-else-if="state === 'enabled'">
                <p class="text-sm">
                    Enabled — generated
                    {{
                        props.signing.generated_at
                            ? formatTimestamp(props.signing.generated_at)
                            : ''
                    }}.
                </p>
                <!-- design-10 `## Amendment — Screen 6 state 3's ordinary-branch
                     disclosure` (2026-08-28) — the ordinary (no overlap yet)
                     demote-not-discard copy, connecting Regenerate signing
                     secret to End overlap now before the member ever clicks
                     Regenerate. Rendered verbatim per that amendment. -->
                <p class="text-sm text-muted-foreground">
                    Regenerating keeps your current secret working for the next
                    24 hours, for every destination this proxy has, so you don't
                    need a coordinated cutover. To stop it early — for example
                    if it's been leaked — use End overlap now, which appears
                    here and on the Signing card once you regenerate.
                </p>
            </template>

            <template v-else-if="state === 'overlap'">
                <p class="text-sm">
                    Enabled — generated
                    {{
                        props.signing.generated_at
                            ? formatTimestamp(props.signing.generated_at)
                            : ''
                    }}.
                </p>
                <p class="text-sm">
                    A rotation is in progress — your previous secret is still
                    honoured until
                    {{
                        props.signing.overlap_expires_at
                            ? formatTimestamp(props.signing.overlap_expires_at)
                            : ''
                    }}.
                </p>
                <Button
                    v-if="props.canUpdate"
                    variant="outline"
                    class="w-fit"
                    :disabled="busy"
                    @click="handleEndOverlap"
                >
                    <Spinner v-if="pendingAction === 'endOverlap'" />
                    End overlap now
                </Button>
                <!-- T43 / correction B2 — this is member-facing copy, visible
                     before Regenerate is clicked, not designer commentary:
                     regenerating now discards the currently-honoured
                     previous secret immediately, for every destination this
                     proxy has, rather than letting its 24 hours finish out.
                     Screen 6 state 3 (no overlap yet) deliberately carries no
                     equivalent line — only this state does. -->
                <p class="text-sm text-muted-foreground">
                    Regenerating again now will stop that previous secret being
                    honoured immediately, for every destination this proxy has —
                    its 24 hours will not finish out.
                </p>
            </template>

            <AlertError
                v-if="requestError"
                :errors="[requestError]"
                title="Request failed"
            />

            <DialogFooter class="gap-2">
                <template v-if="state === 'reveal'">
                    <Button
                        ref="doneButtonRef"
                        :disabled="busy"
                        @click="handleDone"
                    >
                        Done
                    </Button>
                </template>
                <template v-else>
                    <DialogClose as-child>
                        <Button variant="ghost" :disabled="busy">
                            Close
                        </Button>
                    </DialogClose>

                    <template v-if="state === 'not-enabled'">
                        <Button
                            v-if="props.canUpdate"
                            :disabled="busy"
                            @click="generate('enable')"
                        >
                            <Spinner v-if="pendingAction === 'enable'" />
                            Enable signing
                        </Button>
                    </template>

                    <template v-else>
                        <Button
                            v-if="props.canUpdate"
                            variant="ghost"
                            :disabled="busy"
                            @click="handleDisable"
                        >
                            <Spinner v-if="pendingAction === 'disable'" />
                            Disable signing
                        </Button>
                        <Button
                            v-if="props.canUpdate"
                            variant="secondary"
                            :disabled="busy"
                            @click="generate('regenerate')"
                        >
                            <Spinner v-if="pendingAction === 'regenerate'" />
                            Regenerate signing secret
                        </Button>
                    </template>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
