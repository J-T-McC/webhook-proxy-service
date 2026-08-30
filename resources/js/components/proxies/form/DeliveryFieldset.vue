<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PROXY_PROCESSING_MODES } from '@/data/proxyProcessingModes';
import {
    PROXY_RETRY_BACKOFF_STRATEGIES,
    proxyRetryBackoffStrategyLabel,
    RETRY_DEFAULT_ATTEMPT_LIMIT,
    RETRY_STRATEGY_DEFAULT,
    RETRY_STRATEGY_DEFAULT_LABEL,
} from '@/data/proxyRetryBackoffStrategies';
import type {
    ProcessingMode,
    ProxyMode,
    RetryBackoffStrategy,
} from '@/types/proxies';

const mode = defineModel<ProxyMode>('mode', { required: true });
const processingMode = defineModel<ProcessingMode>('processingMode', {
    required: true,
});
const retryAttemptLimit = defineModel<string>('retryAttemptLimit', {
    required: true,
});
const retryBackoffStrategy = defineModel<string>('retryBackoffStrategy', {
    required: true,
});

const props = defineProps<{
    /**
     * The proxy's **persisted** mode and retry policy at mount — never
     * anything typed this session. The mode watcher below re-seeds from
     * these, which is what makes that restore correct rather than a
     * resurrection of discarded input.
     */
    initialMode: ProxyMode;
    initialRetryAttemptLimit: number | null;
    initialRetryBackoffStrategy: RetryBackoffStrategy | null;
    errors?: Partial<Record<string, string>>;
    disabled?: boolean;
}>();

// Backoff strategy is the closed set from PROXY_RETRY_BACKOFF_STRATEGIES plus a
// "default" sentinel (the unconfigured state → the exponential system default).
// The sentinel maps to '' so submit still sends null, mirroring the response
// fieldset's status select.
const retryStrategySelect = computed({
    get: () =>
        retryBackoffStrategy.value === ''
            ? RETRY_STRATEGY_DEFAULT
            : retryBackoffStrategy.value,
    set: (value: string) => {
        retryBackoffStrategy.value =
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
// (`props.initialRetry*`, never mutated) rather than leaving them blank (plan
// §Technical ruling 4(b), Revision A). Those props hold the proxy's *persisted*
// configuration, not anything typed in this session, so restoring them is not
// "undoing" the clear above — it is what makes AC14(b)(iii)'s promise ("the
// preserved values are shown … on save") true on the round trip design-07
// Flow C step 3 names ("Changes mind"). Unconditional and idempotent by
// construction: it never materialises a default literal, so an unconfigured
// Enhanced proxy (null columns) round-trips to blank, not to 5/exponential.
const isEnhanced = computed(() => mode.value === 'enhanced');
watch(isEnhanced, (enhanced) => {
    if (!enhanced) {
        retryAttemptLimit.value = '';
        retryBackoffStrategy.value = '';
    } else {
        retryAttemptLimit.value =
            props.initialRetryAttemptLimit?.toString() ?? '';
        retryBackoffStrategy.value = props.initialRetryBackoffStrategy ?? '';
    }
});

// The downgrade disclosure (design-07 Screen 1) renders only while the form's
// *loaded* mode was Enhanced and the *current* selection is Simple — never at
// Create (initialMode is always 'simple' there), never for a proxy that is
// already Simple and stays Simple, and it disappears immediately on switching
// back to Enhanced before submit, with nothing ever sent to the server. It is
// not a gate: it renders alongside the normal Save action with no confirm
// click, checkbox, or modal.
const isDowngrading = computed(
    () => props.initialMode === 'enhanced' && mode.value === 'simple',
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
</script>

<template>
    <Card class="gap-6 p-6">
        <h2 class="text-base font-semibold">Delivery</h2>

        <fieldset class="grid gap-4">
            <legend class="text-sm font-medium">Mode and processing</legend>

            <div class="grid gap-2">
                <div class="flex items-center gap-2">
                    <Label for="mode">Mode</Label>
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
                        <TooltipContent class="max-w-xs">
                            <p>
                                Automatic retry, payload capture, retention, and
                                replay all apply regardless of Mode — this only
                                affects dispatched-payload storage and the retry
                                settings below.
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </div>
                <Select v-model="mode" :disabled="props.disabled">
                    <SelectTrigger
                        id="mode"
                        class="w-full sm:w-64"
                        :aria-invalid="props.errors?.mode ? 'true' : undefined"
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
                    Enhanced stores what was actually dispatched and unlocks the
                    retry settings below.
                </p>
                <span id="mode-error">
                    <InputError :message="props.errors?.mode" />
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
                <Select v-model="processingMode" :disabled="props.disabled">
                    <SelectTrigger
                        id="processing_mode"
                        class="w-full sm:w-64"
                        :aria-invalid="
                            props.errors?.processing_mode ? 'true' : undefined
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
                    Async (default) delivers in parallel, no order guaranteed.
                    FIFO preserves order, at lower throughput. Set independently
                    of Mode.
                </p>
                <span id="processing-error">
                    <InputError :message="props.errors?.processing_mode" />
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
                    v-model="retryAttemptLimit"
                    type="number"
                    min="1"
                    max="10"
                    class="w-full sm:w-32"
                    :disabled="props.disabled"
                    :aria-invalid="
                        props.errors?.retry_attempt_limit ? 'true' : undefined
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
                    <InputError :message="props.errors?.retry_attempt_limit" />
                </span>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center gap-2">
                    <Label for="retry_backoff_strategy">Backoff strategy</Label>
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
                        <TooltipContent class="max-w-xs">
                            <p>
                                Exponential increases the wait each attempt;
                                fixed interval stays constant. Always bounded
                                well inside the 30-day retention window.
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </div>
                <Select
                    v-model="retryStrategySelect"
                    :disabled="props.disabled"
                >
                    <SelectTrigger
                        id="retry_backoff_strategy"
                        class="w-full sm:w-64"
                        :aria-invalid="
                            props.errors?.retry_backoff_strategy
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
                        :message="props.errors?.retry_backoff_strategy"
                    />
                </span>
            </div>
        </fieldset>
    </Card>
</template>
