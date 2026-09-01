<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { destinationValidationStatusOption } from '@/data/destinationValidationStates';
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
// framing) and a non-validated selection under `destinations` (#18 AC9,
// review-18 finding 2 — the refusal must be shown, "not silently do
// nothing"). Neither is a typed field on this form's own data, so read both
// through the untyped bag Inertia actually populates.
const requestErrors = computed(() => {
    const errors = form.errors as Record<string, string>;

    return [errors.event, errors.destinations].filter(
        (message): message is string => Boolean(message),
    );
});

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

            <fieldset class="grid gap-3 pt-1">
                <legend class="mb-1 text-sm font-medium">
                    Choose destinations
                </legend>

                <Label class="flex items-center gap-3">
                    <Checkbox
                        :model-value="selectAllState"
                        :disabled="form.processing"
                        aria-label="Select all destinations"
                        @update:model-value="toggleSelectAll"
                    />
                    <span>Select all</span>
                </Label>

                <TooltipProvider>
                    <Label
                        v-for="destination in props.destinations"
                        :key="destination.id"
                        class="flex min-w-0 items-center gap-3"
                    >
                        <Checkbox
                            :model-value="isChecked(destination.id)"
                            :disabled="form.processing"
                            :aria-label="`${destination.http_method} ${destination.url}`"
                            @update:model-value="toggle(destination.id)"
                        />
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <span
                                    class="min-w-0 flex-1 truncate font-mono text-sm"
                                    >{{ destination.http_method }}
                                    {{ destination.url }}</span
                                >
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                <p
                                    class="text-left break-all whitespace-normal"
                                >
                                    {{ destination.http_method }}
                                    {{ destination.url }}
                                </p>
                            </TooltipContent>
                        </Tooltip>
                        <!-- AC31 (review-18 finding 8): a non-Validated
                        destination is flagged where it is offered, so the
                        member learns before submitting, not from the refusal.
                        No badge on Validated rows — the normal case stays
                        quiet. -->
                        <Badge
                            v-if="destination.validation_status !== 'validated'"
                            :variant="
                                destinationValidationStatusOption(
                                    destination.validation_status,
                                ).variant
                            "
                            class="shrink-0"
                        >
                            {{
                                destinationValidationStatusOption(
                                    destination.validation_status,
                                ).label
                            }}
                        </Badge>
                    </Label>
                </TooltipProvider>
            </fieldset>

            <AlertError
                v-if="requestErrors.length > 0"
                :errors="requestErrors"
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
