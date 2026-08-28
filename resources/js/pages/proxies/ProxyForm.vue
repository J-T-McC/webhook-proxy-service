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
import { formatTimestamp } from '@/lib/format';
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
    /** `StandardWebhooks::TOLERANCE_SECONDS` (T7), single-sourced — never a
     * hand-typed "5 minutes" (AC53). */
    standardWebhooksTolerance: number;
    /** The `security` prop (T22) — absent on Create (no proxy resource exists
     * yet, plan-10 Technical ruling 3), present on Edit. Governs the
     * Verification section's write-only set/unset/overlap states (Screen 1). */
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

// Verification (Screen 1; AC23, AC24, AC26) — mount-seeded from `security`
// (never re-read after mount, matching `ProxyForm.vue`'s existing
// mount-seeded-vs-in-session-typed distinction, plan-07 §Technical ruling 4).
// `security` is undefined on Create, so every one of these is the "no
// verification configured yet" default there.
const initialVerificationScheme = props.security?.verification.scheme ?? null;
const initialVerificationHeaderName =
    props.security?.verification.header_name ?? null;
const initialVerificationSecretSet =
    props.security?.verification.secret_set ?? false;
const initialVerificationSecretChangedAt =
    props.security?.verification.secret_changed_at ?? null;
const initialVerificationOverlapExpiresAt =
    props.security?.verification.overlap_expires_at ?? null;

// The response fields are held as strings for the inputs; empty status means
// "unconfigured" and is normalised back to null on submit (below), so leaving it
// at the default persists NULL (the resolver then returns the default 202). The
// retry fields follow the same idiom: blank attempt limit / 'default' strategy
// sentinel both normalise back to null on submit. Verification follows the same
// idiom again: an empty `verification_scheme` (translated to/from the Select's
// "none" sentinel below, N2) submits as "not required".
const form = useForm({
    name: props.initial.name,
    mode: props.initial.mode,
    processing_mode: props.initial.processingMode,
    response_status: props.initial.responseStatus?.toString() ?? '',
    response_body: props.initial.responseBody ?? '',
    retry_attempt_limit: props.initial.retryAttemptLimit?.toString() ?? '',
    retry_backoff_strategy: props.initial.retryBackoffStrategy ?? '',
    verification_scheme: initialVerificationScheme ?? '',
    verification_header_name: initialVerificationHeaderName ?? '',
    // Write-only (AC26) — never pre-filled with anything read back from
    // storage; there is nothing to pre-fill it with in the first place.
    verification_secret: '',
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

// Verification — Screen 1 (AC23, AC24, AC26, AC29-ruling-2a; Flows A, B).
// "none" is the Select sentinel for "Not required" (N2 — the underlying
// Select primitive rejects an empty-string item value); `form.verification_scheme`
// itself stays '' internally so it submits as "not required" without a
// transform() step, matching `statusSelect`'s own sentinel-translation idiom.
const VERIFICATION_NOT_REQUIRED = 'none';
const verificationSchemeSelect = computed({
    get: () =>
        form.verification_scheme === ''
            ? VERIFICATION_NOT_REQUIRED
            : form.verification_scheme,
    set: (value: string) => {
        form.verification_scheme =
            value === VERIFICATION_NOT_REQUIRED ? '' : value;
    },
});

// The specification's tolerance (T7's TOLERANCE_SECONDS), single-sourced —
// never a hand-typed "5 minutes" (AC53). 300 seconds / 60 = 5 minutes exactly.
const toleranceMinutes = computed(() => props.standardWebhooksTolerance / 60);

// Clicking Replace switches the collapsed "Secret set" status line for a
// blank, editable field — never pre-filled (AC26). `verificationSecretIsSet`
// governs which one renders: true whenever a live secret already exists
// (scheme-agnostic — SecretStore's `verification` purpose holds at most one
// secret regardless of which scheme currently uses it) and Replace hasn't
// been clicked this session.
const verificationReplaceClicked = ref(false);
const verificationSecretIsSet = computed(
    () => initialVerificationSecretSet && !verificationReplaceClicked.value,
);

function replaceVerificationSecret(): void {
    verificationReplaceClicked.value = true;
    form.verification_secret = '';
}

// Switching scheme clears the in-session, unsaved secret field and resets the
// Replace disclosure (design-10 Screen 1: "the same data operation
// design-07/design-06 already apply to the Retry-policy fieldset on a Mode
// change" — read plan-07 §Technical ruling 4 before touching this; review-07's
// Major came from getting exactly this wrong). The header name follows the
// same mount-seeded-vs-in-session-typed distinction as the Retry fieldset:
// switching TO the originally-persisted `shared-secret` scheme reseeds it from
// `initialVerificationHeaderName` (never blank, never a stale in-session
// value); switching to anything else clears it, since `prohibited_unless`
// forbids submitting it outside that scheme.
watch(
    () => form.verification_scheme,
    (scheme) => {
        verificationReplaceClicked.value = false;
        form.verification_secret = '';
        form.verification_header_name =
            scheme === 'shared-secret' && scheme === initialVerificationScheme
                ? (initialVerificationHeaderName ?? '')
                : '';
    },
);

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
        <Card class="gap-6 p-6">
            <!-- Details -->
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="Stripe → billing services"
                    :disabled="form.processing"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                    aria-describedby="name-help name-error"
                />
                <p id="name-help" class="text-sm text-muted-foreground">
                    A name to recognise this proxy.
                </p>
                <span id="name-error">
                    <InputError :message="form.errors.name" />
                </span>
            </div>

            <div class="grid gap-2">
                <Label for="mode">Mode</Label>
                <Select v-model="form.mode" :disabled="form.processing">
                    <SelectTrigger
                        id="mode"
                        class="w-full sm:w-64"
                        :aria-invalid="form.errors.mode ? 'true' : undefined"
                        aria-describedby="mode-help mode-error"
                    >
                        <SelectValue placeholder="Select a mode" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="simple">Simple</SelectItem>
                        <SelectItem value="enhanced">Enhanced</SelectItem>
                    </SelectContent>
                </Select>
                <p id="mode-help" class="text-sm text-muted-foreground">
                    Enhanced mode stores the payload actually dispatched,
                    separately from the payload received, and lets this proxy
                    configure its own retry attempts and backoff strategy below.
                    Automatic retry, payload capture, retention, and replay
                    apply to every proxy regardless of Mode.
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
                    <AlertDescription class="text-blue-900 dark:text-blue-100">
                        <ul class="list-disc space-y-1 pl-4">
                            <li>
                                Enhanced-only steps — payload storage and retry
                                configuration — stop running for events
                                processed after you save. Automatic retry,
                                payload capture, retention, and replay are
                                unaffected; they apply to every proxy regardless
                                of mode.
                            </li>
                            <li>
                                Dispatched payloads already stored for this
                                proxy's past events are kept, unchanged, and
                                expire on their normal 30-day schedule — the
                                same as always. Nothing is deleted by this
                                switch.
                            </li>
                            <li>
                                Any retry configuration you've saved for this
                                proxy is kept but stops applying while it's
                                Simple — the system default ({{
                                    defaultAttemptLimit
                                }}
                                attempts, {{ defaultBackoffStrategyLower }})
                                governs meanwhile. It applies again, with the
                                same values, if you turn Enhanced back on.
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
                            form.errors.processing_mode ? 'true' : undefined
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
                <p id="processing-help" class="text-sm text-muted-foreground">
                    Independent of the Mode setting above. Async (default)
                    delivers this proxy's events to its destinations in
                    parallel, with no guaranteed order — the right choice for
                    most, higher-throughput traffic. FIFO delivers this proxy's
                    events in the order they were received; it trades throughput
                    for strict ordering, so FIFO is necessarily more serialized
                    and slower than Async, not a free upgrade.
                </p>
                <span id="processing-error">
                    <InputError :message="form.errors.processing_mode" />
                </span>
            </div>

            <!-- Verification (Screen 1; AC23, AC24, AC26, AC29-ruling-2a; Flows A, B) -->
            <fieldset class="grid gap-4">
                <legend class="text-sm font-medium">Verification</legend>
                <p class="text-sm text-muted-foreground">
                    Require an incoming request to prove it's really from your
                    sender before anything is captured. Off by default —
                    existing proxies are unaffected.
                </p>

                <div class="grid gap-2">
                    <Label for="verification_scheme">Scheme</Label>
                    <Select
                        v-model="verificationSchemeSelect"
                        :disabled="form.processing"
                    >
                        <SelectTrigger
                            id="verification_scheme"
                            class="w-full sm:w-96"
                            :aria-invalid="
                                form.errors.verification_scheme
                                    ? 'true'
                                    : undefined
                            "
                            aria-describedby="verification-scheme-error"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="VERIFICATION_NOT_REQUIRED">
                                Not required
                            </SelectItem>
                            <SelectItem value="standard-webhooks">
                                My sender already implements Standard Webhooks
                            </SelectItem>
                            <SelectItem value="shared-secret">
                                My sender sends a shared secret in a header
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span id="verification-scheme-error">
                        <InputError
                            :message="form.errors.verification_scheme"
                        />
                    </span>
                </div>

                <div
                    v-if="form.verification_scheme === 'shared-secret'"
                    class="grid gap-2"
                >
                    <Label for="verification_header_name">Header name</Label>
                    <Input
                        id="verification_header_name"
                        v-model="form.verification_header_name"
                        type="text"
                        placeholder="X-Signature"
                        :disabled="form.processing"
                        :aria-invalid="
                            form.errors.verification_header_name
                                ? 'true'
                                : undefined
                        "
                        aria-describedby="verification-header-name-help verification-header-name-error"
                    />
                    <p
                        id="verification-header-name-help"
                        class="text-sm text-muted-foreground"
                    >
                        The header your sender sends the secret in.
                        Case-sensitive as your sender configures it.
                    </p>
                    <span id="verification-header-name-error">
                        <InputError
                            :message="form.errors.verification_header_name"
                        />
                    </span>
                </div>

                <div
                    v-if="
                        form.verification_scheme === 'shared-secret' ||
                        form.verification_scheme === 'standard-webhooks'
                    "
                    class="grid gap-2"
                >
                    <Label for="verification_secret">Secret value</Label>

                    <template v-if="verificationSecretIsSet">
                        <p class="text-sm">
                            Secret set — changed
                            {{
                                formatTimestamp(
                                    initialVerificationSecretChangedAt as string,
                                )
                            }}
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="w-fit"
                            aria-label="Replace verification secret"
                            :disabled="form.processing"
                            @click="replaceVerificationSecret"
                        >
                            Replace
                        </Button>
                    </template>
                    <template v-else>
                        <Input
                            id="verification_secret"
                            v-model="form.verification_secret"
                            type="password"
                            autocomplete="off"
                            :disabled="form.processing"
                            :aria-invalid="
                                form.errors.verification_secret
                                    ? 'true'
                                    : undefined
                            "
                            aria-describedby="verification-secret-help verification-secret-error"
                        />
                        <!-- C5 (AC29 ruling 2a): the 24-hour-overlap disclosure,
                             shown once Replace is clicked on an
                             already-configured proxy — before save, branched
                             on whether a rotation is already running. -->
                        <p
                            v-if="
                                verificationReplaceClicked &&
                                initialVerificationSecretSet
                            "
                            class="text-sm text-muted-foreground"
                        >
                            <template
                                v-if="initialVerificationOverlapExpiresAt"
                            >
                                You already have a previous secret from your
                                last rotation, still honoured until
                                {{
                                    formatTimestamp(
                                        initialVerificationOverlapExpiresAt,
                                    )
                                }}. Saving a new secret now stops that previous
                                secret being honoured immediately — its 24 hours
                                do not finish out.
                            </template>
                            <template v-else>
                                Your current secret keeps working for 24 hours
                                after you save this, so you can update your
                                sender without a coordinated cutover. To stop it
                                early — for example if it's been leaked — use
                                End overlap now on this proxy's page after
                                saving.
                            </template>
                        </p>
                    </template>

                    <p
                        id="verification-secret-help"
                        class="text-sm text-muted-foreground"
                    >
                        <template
                            v-if="form.verification_scheme === 'shared-secret'"
                        >
                            The exact value your sender will send in that
                            header.
                        </template>
                        <template v-else>
                            The secret your sender issued you for this
                            integration. This product never generates it for you
                            — paste the value they gave you.
                        </template>
                    </p>
                    <span id="verification-secret-error">
                        <InputError
                            :message="form.errors.verification_secret"
                        />
                    </span>
                </div>

                <div
                    v-if="form.verification_scheme === 'standard-webhooks'"
                    class="grid gap-2 rounded-md border border-input p-3"
                >
                    <p class="text-sm">
                        Your sender must send these three headers on every
                        request:
                    </p>
                    <ul class="list-disc pl-5 text-sm text-muted-foreground">
                        <li>webhook-id</li>
                        <li>webhook-timestamp</li>
                        <li>
                            webhook-signature — one or more HMAC-SHA256
                            signatures, base64-encoded, space-delimited
                        </li>
                    </ul>
                    <p class="text-sm text-muted-foreground">
                        Requests whose webhook-timestamp is more than
                        {{ toleranceMinutes }} minutes from the current time are
                        rejected, per the Standard Webhooks specification.
                    </p>
                </div>
            </fieldset>

            <!-- Retry policy (enhanced mode only, Flow F) -->
            <fieldset v-if="isEnhanced" class="grid gap-4">
                <legend class="text-sm font-medium">Retry policy</legend>
                <p class="text-sm text-muted-foreground">
                    Applies to automatic re-attempts after a failed delivery to
                    a destination. Available on Enhanced-mode proxies;
                    Simple-mode proxies use the fixed system default ({{
                        defaultAttemptLimit
                    }}
                    attempts, {{ defaultBackoffStrategyLower }} backoff).
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
                            form.errors.retry_attempt_limit ? 'true' : undefined
                        "
                        aria-describedby="retry-attempt-limit-help retry-attempt-limit-error"
                    />
                    <p
                        id="retry-attempt-limit-help"
                        class="text-sm text-muted-foreground"
                    >
                        Leave blank to use the default (5). Maximum 10.
                    </p>
                    <span id="retry-attempt-limit-error">
                        <InputError
                            :message="form.errors.retry_attempt_limit"
                        />
                    </span>
                </div>

                <div class="grid gap-2">
                    <Label for="retry_backoff_strategy">Backoff strategy</Label>
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
                            aria-describedby="retry-backoff-strategy-help retry-backoff-strategy-error"
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
                    <p
                        id="retry-backoff-strategy-help"
                        class="text-sm text-muted-foreground"
                    >
                        Exponential increases the wait between attempts each
                        time; fixed interval waits the same amount every time.
                        Either way, retries are always bounded well inside your
                        team's 30-day payload retention window.
                    </p>
                    <span id="retry-backoff-strategy-error">
                        <InputError
                            :message="form.errors.retry_backoff_strategy"
                        />
                    </span>
                </div>
            </fieldset>

            <!-- Upstream response (acknowledgement, returned before delivery) -->
            <div class="grid gap-2">
                <Label for="response_status">Response status code</Label>
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
                    The HTTP status returned to the sender the moment the
                    webhook is received — an acknowledgement, sent immediately
                    and independently of whether delivery to your destinations
                    succeeds. Choose 200, 202, or 204; 204 (No Content) sends an
                    empty body. Leave as Default to return 202 Accepted.
                </p>
                <span id="response-status-error">
                    <InputError :message="form.errors.response_status" />
                </span>
            </div>

            <div class="grid gap-2">
                <Label for="response_body">Response body</Label>
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
                    An optional fixed body returned with the acknowledgement
                    (for example a verification challenge echo). It is a static
                    reply, not a delivery report, and never reflects your
                    destinations' responses. Leave blank for an empty body; 204
                    (No Content) always sends an empty body, so this field is
                    disabled when 204 is selected.
                </p>
                <span id="response-body-error">
                    <InputError :message="form.errors.response_body" />
                </span>
            </div>

            <!-- Sensitive fields (Screen 2; AC12, AC13, AC19, C4, N4) -->
            <fieldset class="grid gap-4">
                <legend class="text-sm font-medium">Sensitive fields</legend>
                <p class="text-sm text-muted-foreground">
                    Values in these fields are hidden wherever this proxy's
                    stored payloads are shown. This never changes what's stored
                    or what's delivered — see a payload's Reveal to check.
                </p>

                <div class="grid gap-2">
                    <p class="text-sm font-medium">Always hidden</p>
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="name in defaultSensitiveFieldNames"
                            :key="name"
                            variant="secondary"
                        >
                            {{ name }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Case and separators don't matter — password, Password
                        and pass_word are all this same name.
                    </p>
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
                            <Label for="sensitive-field-add" class="sr-only">
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
                        <InputError :message="form.errors.sensitive_fields" />
                    </span>
                </div>
            </fieldset>

            <!-- Destinations -->
            <DestinationRows
                v-model="form.destinations"
                :errors="form.errors"
                :disabled="form.processing"
            />
            <InputError :message="form.errors.destinations" />

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ submitLabel }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="cancelHref">Cancel</Link>
                </Button>
            </div>
        </Card>
    </form>
</template>
