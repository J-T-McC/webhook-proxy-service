<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import proxyEventRoutes from '@/routes/proxies/events';
import type { ProxyDestination } from '@/types/proxies';

/**
 * The replay confirmation dialog (Screen 4, Flow D; AC10–AC12, AC14) — a
 * plain `Dialog`, not `AlertDialog` (design-06's flagged, PM-accepted call:
 * replay creates new delivery attempts, it deletes/alters nothing, so
 * deliberateness comes from content — the warning sentence, nothing
 * pre-checked, a count-bearing Confirm label — not destructive styling).
 * Shared by both `proxies/events/Index.vue` (row action) and
 * `proxies/events/Show.vue` (header action).
 */
const props = defineProps<{
    open: boolean;
    teamSlug: string;
    proxyId: number;
    /** The proxy's current live destinations (AC10 — no arbitrary/ad-hoc targets). */
    destinations: ProxyDestination[];
    isFifo: boolean;
    /** The event being replayed; null while the dialog is closed/unset. */
    eventId: number | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const form = useForm({
    destinations: [] as number[],
});

// The server rejects an expired-event replay under an `event` key
// (`ValidationException::withMessages(['event' => ...])`, AC15's lifecycle
// framing) — not a field on this form's own data, so `useForm`'s error type
// doesn't know about it; read it through the same untyped bag Inertia
// actually populates.
const requestError = computed(
    () => (form.errors as Record<string, string>).event,
);

// Re-opening after a close (success or cancel) always resets to
// nothing-checked — selections are never remembered between opens.
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            form.reset();
            form.clearErrors();
        }
    },
);

function isChecked(destinationId: number): boolean {
    return form.destinations.includes(destinationId);
}

function toggle(destinationId: number): void {
    form.destinations = isChecked(destinationId)
        ? form.destinations.filter((id) => id !== destinationId)
        : [...form.destinations, destinationId];
}

const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (form.destinations.length === 0) {
        return false;
    }

    return form.destinations.length === props.destinations.length
        ? true
        : 'indeterminate';
});

function toggleSelectAll(value: boolean | 'indeterminate'): void {
    form.destinations =
        value === true ? props.destinations.map((d) => d.id) : [];
}

const confirmLabel = computed(() => {
    const count = form.destinations.length;

    return `Replay to ${count} destination${count === 1 ? '' : 's'}`;
});

function submit(): void {
    if (props.eventId === null || form.destinations.length === 0) {
        return;
    }

    form.post(
        proxyEventRoutes.replay({
            current_team: props.teamSlug,
            proxy: props.proxyId,
            event: props.eventId,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        },
    );
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Replay this event?</DialogTitle>
                <DialogDescription>
                    Sends this event's stored payload to the destinations you
                    choose below, as a new delivery. Your destinations receive
                    real traffic again — this is not a preview.
                </DialogDescription>
            </DialogHeader>

            <Alert
                v-if="props.isFifo"
                class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100"
            >
                <AlertDescription class="text-blue-900 dark:text-blue-100">
                    This proxy is FIFO — your replay joins the back of the line
                    and is delivered in received order.
                </AlertDescription>
            </Alert>

            <fieldset class="grid gap-3">
                <legend class="text-sm font-medium">Choose destinations</legend>

                <Label class="flex items-center gap-3">
                    <Checkbox
                        :model-value="selectAllState"
                        :disabled="form.processing"
                        @update:model-value="toggleSelectAll"
                    />
                    <span>Select all</span>
                </Label>

                <Label
                    v-for="destination in props.destinations"
                    :key="destination.id"
                    class="flex items-center gap-3"
                >
                    <Checkbox
                        :model-value="isChecked(destination.id)"
                        :disabled="form.processing"
                        @update:model-value="toggle(destination.id)"
                    />
                    <span class="truncate font-mono text-sm"
                        >{{ destination.http_method }}
                        {{ destination.url }}</span
                    >
                </Label>
            </fieldset>

            <AlertError
                v-if="requestError"
                :errors="[requestError]"
                title="Replay failed"
            />

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="ghost" :disabled="form.processing">
                        Cancel
                    </Button>
                </DialogClose>

                <Button
                    :disabled="
                        form.destinations.length === 0 || form.processing
                    "
                    @click="submit"
                >
                    <Spinner v-if="form.processing" />
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
