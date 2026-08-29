<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Info, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import DestinationRows from '@/components/DestinationRows.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PROXY_PROCESSING_MODES } from '@/data/proxyProcessingModes';
import {
    PROXY_RESPONSE_STATUS_DEFAULT_LABEL,
    PROXY_RESPONSE_STATUSES,
    proxyStatusForcesEmptyBody,
} from '@/data/proxyResponseStatuses';
import {
    PROXY_RETRY_BACKOFF_STRATEGIES,
    proxyRetryBackoffStrategyLabel,
    RETRY_DEFAULT_ATTEMPT_LIMIT,
    RETRY_STRATEGY_DEFAULT,
    RETRY_STRATEGY_DEFAULT_LABEL,
} from '@/data/proxyRetryBackoffStrategies';
import type {
    DestinationRow,
    ProcessingMode,
    ProxyMode,
    ProxyResponseStatus,
    ProxySecurity,
    RetryBackoffStrategy,
} from '@/types/proxies';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    /** The fixed AC12 default list (T11) — rendered literally, one badge per
     * entry, never summarised (Screen 2, correction C4). */
    defaultSensitiveFieldNames: string[];
    /** The `security` prop (T22) — absent on Create (no proxy resource exists
     * yet, plan-10 Technical ruling 3), present on Edit. Screen 3's credential
     * subsection reads it. */
    security?: ProxySecurity | null;
    initial: {
        name: string;
        mode: ProxyMode;
        processingMode: ProcessingMode;
        responseStatus: ProxyResponseStatus | null;
        responseBody: string | null;
        retryAttemptLimit: number | null;
        retryBackoffStrategy: RetryBackoffStrategy | null;
        /** This proxy's own AC13 additions — never the default list. */
        sensitiveFields: string[];
        destinations: DestinationRow[];
    };
}>();

// The response fields are held as strings for the inputs; empty status means
// "unconfigured" and is normalised back to null on submit (below), so leaving it
// at the default persists NULL (the resolver then returns the default 202). The
// retry fields follow the same idiom: blank attempt limit / 'default' strategy
// sentinel both normalise back to null on submit.
const form = useForm({
    name: props.initial.name,
    mode: props.initial.mode,
    processing_mode: props.initial.processingMode,
    response_status: props.initial.responseStatus?.toString() ?? '',
    response_body: props.initial.responseBody ?? '',
    retry_attempt_limit: props.initial.retryAttemptLimit?.toString() ?? '',
    retry_backoff_strategy: props.initial.retryBackoffStrategy ?? '',
    sensitive_fields: [...props.initial.sensitiveFields],
    // Screen 3 (T30): the header name defaults to 'Authorization' for a row
    // with no credential of its own (design-10: "New row, this session" /
    // "Existing, no credential" both read Header name (Authorization)); the
    // secret is always write-only (never pre-filled — there is nothing to
    // pre-fill it with, AC33) regardless of whether this row already has a
    // credential.
    destinations: props.initial.destinations.map((row) => ({
        ...row,
        credential_header_name: row.credential_header_name ?? 'Authorization',
        credential_secret: '',
    })),
});

// Backoff strategy is the closed set from PROXY_RETRY_BACKOFF_STRATEGIES plus a
// "default" sentinel (the unconfigured state → the exponential system default).
// The sentinel maps to '' so submit still sends null, mirroring `statusSelect`.
const retryStrategySelect = computed({
    get: () =>
        form.retry_backoff_strategy === ''
            ? RETRY_STRATEGY_DEFAULT
            : form.retry_backoff_strategy,
    set: (value: string) => {
        form.retry_backoff_strategy =
            value === RETRY_STRATEGY_DEFAULT ? '' : value;
    },
});

// The Retry policy section only renders in Enhanced mode (Flow F). Switching
// Enhanced → Simple clears both fields to their default-sentinel state the
// moment the section unmounts — a data operation, not a CSS toggle, so no stale
// value can ever be submitted for a simple-mode proxy (Flow F step 4). This is
// the deliberate discard of *in-session typed* values and is unchanged.
//
// Switching back Simple → Enhanced re-seeds both fields from the mount seed
// (`props.initial`, never mutated) rather than leaving them blank (plan
// §Technical ruling 4(b), Revision A). `props.initial.*` holds the proxy's
// *persisted* configuration, not anything typed in this session, so restoring
// it is not "undoing" the clear above — it is what makes AC14(b)(iii)'s promise
// ("the preserved values are shown … on save") true on the round trip design-07
// Flow C step 3 names ("Changes mind"). Unconditional and idempotent by
// construction: it never materialises a default literal, so an unconfigured
// Enhanced proxy (null columns) round-trips to blank, not to 5/exponential.
const isEnhanced = computed(() => form.mode === 'enhanced');
watch(isEnhanced, (enhanced) => {
    if (!enhanced) {
        form.retry_attempt_limit = '';
        form.retry_backoff_strategy = '';
    } else {
        form.retry_attempt_limit =
            props.initial.retryAttemptLimit?.toString() ?? '';
        form.retry_backoff_strategy = props.initial.retryBackoffStrategy ?? '';
    }
});

// The downgrade disclosure (design-07 Screen 1) renders only while the form's
// *loaded* mode was Enhanced and the *current* selection is Simple — never at
// Create (initial.mode is always 'simple' there), never for a proxy that is
// already Simple and stays Simple, and it disappears immediately on switching
// back to Enhanced before submit, with nothing ever sent to the server. It is
// not a gate: it renders alongside the normal Save action with no confirm
// click, checkbox, or modal.
const isDowngrading = computed(
    () => props.initial.mode === 'enhanced' && form.mode === 'simple',
);

// The disclosure's third bullet and the Retry policy fieldset's help text
// both interpolate the system default rather than hard-coding a second copy
// of it — the same source Show's Retry policy card display helpers read from
// (@/data/proxyRetryBackoffStrategies), so a future default change can't
// leave either copy stale relative to the card it describes.
// `proxyRetryBackoffStrategyLabel` is title-cased for its other callers (the
// Select item, the Show card); `defaultBackoffStrategyLower` is that same
// value lower-cased for use mid-sentence here, matching design-07's approved
// copy ("5 attempts, exponential").
const defaultAttemptLimit = RETRY_DEFAULT_ATTEMPT_LIMIT;
const defaultBackoffStrategyLower =
    proxyRetryBackoffStrategyLabel(null).toLowerCase();

// Status is the closed set from PROXY_RESPONSE_STATUSES plus a "default" sentinel
// (the unconfigured state → 202). The sentinel maps to '' so submit still sends
// null.
const STATUS_DEFAULT = 'default';
const statusSelect = computed({
    get: () =>
        form.response_status === '' ? STATUS_DEFAULT : form.response_status,
    set: (value: string) => {
        form.response_status = value === STATUS_DEFAULT ? '' : value;
    },
});

// The selected status as its typed value (null when unconfigured); the select is
// closed to the shared set + the '' sentinel, so the numeric coercion is exact.
const selectedStatus = computed<ProxyResponseStatus | null>(() =>
    form.response_status === ''
        ? null
        : (Number(form.response_status) as ProxyResponseStatus),
);

// 204 = No Content couples to an empty body (AC12): selecting a status flagged
// emptyBody disables the body field and clears any previously entered body.
const bodyDisabled = computed(() =>
    proxyStatusForcesEmptyBody(selectedStatus.value),
);
watch(selectedStatus, (status) => {
    if (proxyStatusForcesEmptyBody(status)) {
        form.response_body = '';
    }
});

// Sensitive fields — Screen 2 (AC12, AC13, AC19, C4, N4). No enable/disable
// control exists anywhere here: obfuscation is always on (N4).
const sensitiveFieldInput = ref('');

function addSensitiveField(): void {
    const name = sensitiveFieldInput.value.trim();

    if (name === '') {
        return;
    }

    // A duplicate-of-an-existing-addition entry is a silent no-op — no
    // error toast, matching this app's low-ceremony treatment (Screen 2
    // "Duplicate/empty entry" state). A name that also happens to match a
    // default is still accepted here (harmless — AC12/AC13 never conflict);
    // the server is the authority on de-duplication by normalised form.
    if (
        form.sensitive_fields.some(
            (existing) => existing.trim().toLowerCase() === name.toLowerCase(),
        )
    ) {
        sensitiveFieldInput.value = '';

        return;
    }

    form.sensitive_fields = [...form.sensitive_fields, name];
    sensitiveFieldInput.value = '';
}

function removeSensitiveField(index: number): void {
    const next = [...form.sensitive_fields];
    next.splice(index, 1);
    form.sensitive_fields = next;
}

const formEl = ref<HTMLFormElement | null>(null);

function submit(): void {
    form.transform((data) => ({
        ...data,
        // Blank → null (unconfigured); a set status is sent as a number.
        response_status:
            data.response_status === '' ? null : Number(data.response_status),
        response_body: data.response_body === '' ? null : data.response_body,
        // Same idiom for the retry fields: blank/sentinel → null (unconfigured).
        // A Simple-mode submission ALWAYS sends null for both, regardless of
        // the fields' in-memory state — required because the Edit form's
        // initial state is seeded from the persisted values whatever the
        // proxy's mode (T5/T6), while `watch(isEnhanced, ...)` above only
        // clears fields on an in-session change, never on mount. Without this,
        // opening Edit on a Simple proxy holding a dormant retry policy and
        // saving without touching Mode would submit the dormant values
        // alongside mode: 'simple' and be 422'd by prohibited_if on a field
        // the form does not render (plan Risk 4). This is a normalisation,
        // not a gate — the server's omission rule (T1) is authoritative
        // regardless of what a Simple submission carries.
        retry_attempt_limit:
            data.mode === 'simple' || data.retry_attempt_limit === ''
                ? null
                : Number(data.retry_attempt_limit),
        retry_backoff_strategy:
            data.mode === 'simple' || data.retry_backoff_strategy === ''
                ? null
                : data.retry_backoff_strategy,
        // T31 (correction B3; plan-10 § Revision A, technical ruling 15) —
        // the Remove credential signal is derived here, at submit time, not
        // stored as a submitted field on the row itself: `true` whenever
        // this row's Remove credential was clicked this session AND the
        // member has not since typed a new secret into the now-blank field
        // (a later, deliberate act that supersedes the staged removal —
        // "typing into an unconfigured row has always meant 'set this
        // secret'"). `credential_secret` keeps exactly one meaning
        // regardless (a new value, or absent means leave unchanged); this
        // never rewrites it.
        destinations: data.destinations.map((row) => ({
            ...row,
            remove_credential:
                row.credential_removed === true && row.credential_secret === '',
        })),
    })).submit(props.method, props.action, {
        preserveScroll: true,
        onError: () => {
            // Move focus to the first field in error (name, a response field, or
            // a destination row).
            nextTick(() => {
                formEl.value
                    ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus();
            });
        },
    });
}
</script>

<template>
    <form
        ref="formEl"
        class="mx-auto w-full max-w-3xl"
        @submit.prevent="submit"
    >
        <div class="space-y-6">
            <!-- Details -->
            <Card class="gap-6 p-6">
                <h2 class="text-base font-semibold">Details</h2>
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        type="text"
                        placeholder="Stripe → billing services"
                        :disabled="form.processing"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        aria-describedby="name-error"
                    />
                    <span id="name-error">
                        <InputError :message="form.errors.name" />
                    </span>
                </div>
            </Card>

            <!-- Response -->
            <Card class="gap-6 p-6">
                <h2 class="text-base font-semibold">Response</h2>
                <div class="grid gap-2">
                    <Label for="response_status">Status code</Label>
                    <Select v-model="statusSelect" :disabled="form.processing">
                        <SelectTrigger
                            id="response_status"
                            class="w-full sm:w-64"
                            :aria-invalid="
                                form.errors.response_status ? 'true' : undefined
                            "
                            aria-describedby="response-status-help response-status-error"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="STATUS_DEFAULT">
                                {{ PROXY_RESPONSE_STATUS_DEFAULT_LABEL }}
                            </SelectItem>
                            <SelectItem
                                v-for="status in PROXY_RESPONSE_STATUSES"
                                :key="status.value"
                                :value="status.value.toString()"
                            >
                                {{ status.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        id="response-status-help"
                        class="text-sm text-muted-foreground"
                    >
                        Sent immediately, before delivery — independent of
                        destination outcome.
                    </p>
                    <span id="response-status-error">
                        <InputError :message="form.errors.response_status" />
                    </span>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center gap-2">
                        <Label for="response_body">Body</Label>
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="More about the response body"
                                    >
                                        <Info class="size-3.5" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>
                                        Useful for a verification challenge echo
                                        some senders require during setup.
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                    <Input
                        id="response_body"
                        v-model="form.response_body"
                        type="text"
                        placeholder="(empty)"
                        :disabled="form.processing || bodyDisabled"
                        :aria-invalid="
                            form.errors.response_body ? 'true' : undefined
                        "
                        aria-describedby="response-body-help response-body-error"
                    />
                    <p
                        id="response-body-help"
                        class="text-sm text-muted-foreground"
                    >
                        Optional. Disabled when Status code is 204.
                    </p>
                    <span id="response-body-error">
                        <InputError :message="form.errors.response_body" />
                    </span>
                </div>
            </Card>

            <!-- Delivery -->
            <Card class="gap-6 p-6">
                <h2 class="text-base font-semibold">Delivery</h2>

                <fieldset class="grid gap-4">
                    <legend class="text-sm font-medium">
                        Mode and processing
                    </legend>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Label for="mode">Mode</Label>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label="More about Mode"
                                        >
                                            <Info class="size-3.5" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>
                                            Automatic retry, payload capture,
                                            retention, and replay all apply
                                            regardless of Mode — this only
                                            affects dispatched-payload storage
                                            and the retry settings below.
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <Select v-model="form.mode" :disabled="form.processing">
                            <SelectTrigger
                                id="mode"
                                class="w-full sm:w-64"
                                :aria-invalid="
                                    form.errors.mode ? 'true' : undefined
                                "
                                aria-describedby="mode-help mode-error"
                            >
                                <SelectValue placeholder="Select a mode" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="simple">Simple</SelectItem>
                                <SelectItem value="enhanced">
                                    Enhanced
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p id="mode-help" class="text-sm text-muted-foreground">
                            Enhanced stores what was actually dispatched and
                            unlocks the retry settings below.
                        </p>
                        <span id="mode-error">
                            <InputError :message="form.errors.mode" />
                        </span>
                    </div>

                    <!-- Downgrade disclosure (Enhanced → Simple edit only, AC13/AC14(c)) -->
                    <div aria-live="polite">
                        <Alert
                            v-if="isDowngrading"
                            class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400"
                        >
                            <Info class="size-4" />
                            <AlertTitle>Switching to Simple mode</AlertTitle>
                            <AlertDescription
                                class="text-blue-900 dark:text-blue-100"
                            >
                                <ul class="list-disc space-y-1 pl-4">
                                    <li>
                                        Enhanced-only steps — payload storage
                                        and retry configuration — stop running
                                        for events processed after you save.
                                        Automatic retry, payload capture,
                                        retention, and replay are unaffected;
                                        they apply to every proxy regardless of
                                        mode.
                                    </li>
                                    <li>
                                        Dispatched payloads already stored for
                                        this proxy's past events are kept,
                                        unchanged, and expire on their normal
                                        30-day schedule — the same as always.
                                        Nothing is deleted by this switch.
                                    </li>
                                    <li>
                                        Any retry configuration you've saved for
                                        this proxy is kept but stops applying
                                        while it's Simple — the system default
                                        ({{ defaultAttemptLimit }} attempts,
                                        {{ defaultBackoffStrategyLower }})
                                        governs meanwhile. It applies again,
                                        with the same values, if you turn
                                        Enhanced back on.
                                    </li>
                                </ul>
                            </AlertDescription>
                        </Alert>
                    </div>

                    <div class="grid gap-2">
                        <Label for="processing_mode">Processing</Label>
                        <Select
                            v-model="form.processing_mode"
                            :disabled="form.processing"
                        >
                            <SelectTrigger
                                id="processing_mode"
                                class="w-full sm:w-64"
                                :aria-invalid="
                                    form.errors.processing_mode
                                        ? 'true'
                                        : undefined
                                "
                                aria-describedby="processing-help processing-error"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in PROXY_PROCESSING_MODES"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            id="processing-help"
                            class="text-sm text-muted-foreground"
                        >
                            Async (default) delivers in parallel, no order
                            guaranteed. FIFO preserves order, at lower
                            throughput. Set independently of Mode.
                        </p>
                        <span id="processing-error">
                            <InputError
                                :message="form.errors.processing_mode"
                            />
                        </span>
                    </div>
                </fieldset>

                <!-- Retry policy (enhanced mode only, Flow F) -->
                <fieldset v-if="isEnhanced" class="grid gap-4">
                    <legend class="text-sm font-medium">Retry policy</legend>
                    <p class="text-sm text-muted-foreground">
                        Simple-mode proxies use the fixed default ({{
                            defaultAttemptLimit
                        }}
                        attempts, {{ defaultBackoffStrategyLower }}).
                    </p>

                    <div class="grid gap-2">
                        <Label for="retry_attempt_limit">Attempts</Label>
                        <Input
                            id="retry_attempt_limit"
                            v-model="form.retry_attempt_limit"
                            type="number"
                            min="1"
                            max="10"
                            class="w-full sm:w-32"
                            :disabled="form.processing"
                            :aria-invalid="
                                form.errors.retry_attempt_limit
                                    ? 'true'
                                    : undefined
                            "
                            aria-describedby="retry-attempt-limit-help retry-attempt-limit-error"
                        />
                        <p
                            id="retry-attempt-limit-help"
                            class="text-sm text-muted-foreground"
                        >
                            Default {{ defaultAttemptLimit }}. Max 10.
                        </p>
                        <span id="retry-attempt-limit-error">
                            <InputError
                                :message="form.errors.retry_attempt_limit"
                            />
                        </span>
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Label for="retry_backoff_strategy"
                                >Backoff strategy</Label
                            >
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label="More about Backoff strategy"
                                        >
                                            <Info class="size-3.5" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>
                                            Exponential increases the wait each
                                            attempt; fixed interval stays
                                            constant. Always bounded well inside
                                            the 30-day retention window.
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <Select
                            v-model="retryStrategySelect"
                            :disabled="form.processing"
                        >
                            <SelectTrigger
                                id="retry_backoff_strategy"
                                class="w-full sm:w-64"
                                :aria-invalid="
                                    form.errors.retry_backoff_strategy
                                        ? 'true'
                                        : undefined
                                "
                                aria-describedby="retry-backoff-strategy-error"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="RETRY_STRATEGY_DEFAULT">
                                    {{ RETRY_STRATEGY_DEFAULT_LABEL }}
                                </SelectItem>
                                <SelectItem
                                    v-for="strategy in PROXY_RETRY_BACKOFF_STRATEGIES"
                                    :key="strategy.value"
                                    :value="strategy.value"
                                >
                                    {{ strategy.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <span id="retry-backoff-strategy-error">
                            <InputError
                                :message="form.errors.retry_backoff_strategy"
                            />
                        </span>
                    </div>
                </fieldset>
            </Card>

            <Card class="gap-6 p-6">
                <!-- Sensitive fields (Screen 2; AC12, AC13, AC19, C4, N4) -->
                <fieldset class="grid gap-4">
                    <legend class="text-sm font-medium">
                        Sensitive fields
                    </legend>
                    <p class="text-sm text-muted-foreground">
                        Hidden wherever this proxy's payloads are shown. Storage
                        and delivery are unaffected.
                    </p>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium">Always hidden</p>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label="More about matching for Always hidden fields"
                                        >
                                            <Info class="size-3.5" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>
                                            Matches password, Password,
                                            pass_word, etc. — case and
                                            separators don't matter.
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Badge
                                v-for="name in defaultSensitiveFieldNames"
                                :key="name"
                                variant="secondary"
                            >
                                {{ name }}
                            </Badge>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <p class="text-sm font-medium">
                            Also hidden for this proxy
                        </p>
                        <div
                            v-if="form.sensitive_fields.length > 0"
                            class="flex flex-wrap gap-2"
                        >
                            <Badge
                                v-for="(name, index) in form.sensitive_fields"
                                :key="`${name}-${index}`"
                                variant="outline"
                                class="gap-1 pr-1.5"
                            >
                                {{ name }}
                                <button
                                    type="button"
                                    class="rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="`Remove ${name}`"
                                    :disabled="form.processing"
                                    @click="removeSensitiveField(index)"
                                >
                                    <X class="size-3" />
                                </button>
                            </Badge>
                        </div>

                        <div class="flex gap-2">
                            <div class="grid flex-1 gap-1">
                                <Label
                                    for="sensitive-field-add"
                                    class="sr-only"
                                >
                                    Add field name
                                </Label>
                                <Input
                                    id="sensitive-field-add"
                                    v-model="sensitiveFieldInput"
                                    type="text"
                                    placeholder="e.g. ssn_last4"
                                    :disabled="form.processing"
                                    aria-describedby="sensitive-fields-error"
                                    @keydown.enter.prevent="addSensitiveField"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="form.processing"
                                @click="addSensitiveField"
                            >
                                Add
                            </Button>
                        </div>
                        <span id="sensitive-fields-error">
                            <InputError
                                :message="form.errors.sensitive_fields"
                            />
                        </span>
                    </div>
                </fieldset>
            </Card>

            <!-- Destinations (T6 wraps this in its own Card) -->
            <DestinationRows
                v-model="form.destinations"
                :errors="form.errors"
                :disabled="form.processing"
            />
            <InputError :message="form.errors.destinations" />
        </div>

        <div class="mt-6 flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                {{ submitLabel }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="cancelHref">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
