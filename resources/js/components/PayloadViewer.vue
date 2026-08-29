<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * A retained event's masked-by-default payload block with an explicit
 * whole-payload reveal (Flow C; AC25; design-06 §Components). Content is
 * fetched fresh from `url` only on the Reveal click (ADR-017 Decision 6) —
 * never included in the page's initial Inertia props, and never present in
 * this component's own state until the user explicitly asks for it.
 *
 * Extended (T9; AC16, AC20, AC21, C3, C6, C8, C9, N1): the endpoint now
 * branches on `Content-Type` (ADR-024) — a JSON-parseable payload returns
 * the `{format, document, obfuscated}` envelope, walked here into a
 * pretty-printed rendering where every pointer in `obfuscated` renders as an
 * inert `[Hidden]` token instead of its (already-`null`) value; a non-JSON
 * payload is unchanged from before this feature (AC22).
 */
const props = defineProps<{
    /** The fetch-on-reveal payload endpoint URL (T28's `GET .../payload`). */
    url: string;
}>();

/** The two C3 accessible descriptions, verbatim from design-10 — this
 * component authors no copy of its own. */
const HIDDEN_DESCRIPTIONS: Record<'default' | 'addition', string> = {
    default:
        "Hidden — this field's name matches a product default (password, token, or credit card). It can't be removed from Sensitive fields.",
    addition:
        "Hidden — this field's name matches an addition to this proxy's Sensitive fields list. Remove the name from Sensitive fields to stop hiding it.",
};

/** One piece of the pretty-printed rendering: literal text, or an inert
 * `[Hidden]` token in place of a sensitive value (C6 — the entire value,
 * whatever its type, replaced whole). */
type RenderPart =
    | { kind: 'text'; text: string }
    | { kind: 'hidden'; source: 'default' | 'addition' };

const revealed = ref(false);
const loading = ref(false);
/** The raw text for a non-JSON payload (unchanged design-06 path). */
const payload = ref('');
/** The parsed `document` for a JSON payload, walked by `renderParts` below. */
const jsonDocument = ref<unknown>(null);
/** RFC 6901 pointer → which list matched, from the JSON envelope. */
const jsonObfuscated = ref<Record<string, 'default' | 'addition'>>({});
const format = ref<'text' | 'json'>('text');
const error = ref('');
const announcement = ref('');

/**
 * Escape a JSON Pointer reference token (RFC 6901 § 3) exactly as the
 * server does — `~` before `/` — so a pointer computed here from the same
 * structure the server walked always matches an entry in `obfuscated`.
 */
function escapeSegment(segment: string): string {
    return segment.replace(/~/g, '~0').replace(/\//g, '~1');
}

/**
 * Walks `value` and appends pretty-printed JSON text parts to `parts`,
 * substituting a `[Hidden]` token part wherever `pointer` is present in
 * `jsonObfuscated` — never recursing into a hidden value's own structure
 * (C6), whatever its type.
 */
function walk(
    value: unknown,
    pointer: string,
    indent: number,
    obfuscated: Record<string, 'default' | 'addition'>,
    parts: RenderPart[],
): void {
    if (Object.prototype.hasOwnProperty.call(obfuscated, pointer)) {
        parts.push({ kind: 'hidden', source: obfuscated[pointer] });

        return;
    }

    const pad = '  '.repeat(indent);
    const childPad = '  '.repeat(indent + 1);

    if (Array.isArray(value)) {
        if (value.length === 0) {
            parts.push({ kind: 'text', text: '[]' });

            return;
        }

        parts.push({ kind: 'text', text: '[\n' });
        value.forEach((item, index) => {
            parts.push({ kind: 'text', text: childPad });
            walk(item, `${pointer}/${index}`, indent + 1, obfuscated, parts);
            parts.push({
                kind: 'text',
                text: index < value.length - 1 ? ',\n' : '\n',
            });
        });
        parts.push({ kind: 'text', text: `${pad}]` });

        return;
    }

    if (value !== null && typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>);

        if (entries.length === 0) {
            parts.push({ kind: 'text', text: '{}' });

            return;
        }

        parts.push({ kind: 'text', text: '{\n' });
        entries.forEach(([key, child], index) => {
            parts.push({
                kind: 'text',
                text: `${childPad}${JSON.stringify(key)}: `,
            });
            walk(
                child,
                `${pointer}/${escapeSegment(key)}`,
                indent + 1,
                obfuscated,
                parts,
            );
            parts.push({
                kind: 'text',
                text: index < entries.length - 1 ? ',\n' : '\n',
            });
        });
        parts.push({ kind: 'text', text: `${pad}}` });

        return;
    }

    parts.push({ kind: 'text', text: JSON.stringify(value) });
}

/** The pretty-printed rendering of the revealed JSON document, as an
 * alternating sequence of text runs and `[Hidden]` tokens. Recomputed only
 * while a JSON payload is revealed. */
const renderParts = computed<RenderPart[]>(() => {
    if (format.value !== 'json') {
        return [];
    }

    const parts: RenderPart[] = [];
    walk(jsonDocument.value, '', 0, jsonObfuscated.value, parts);

    return parts;
});

function descriptionFor(source: 'default' | 'addition'): string {
    return HIDDEN_DESCRIPTIONS[source];
}

async function reveal(): Promise<void> {
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch(props.url);

        if (response.status === 410) {
            error.value =
                'This payload has expired since the page loaded and can no longer be viewed.';

            return;
        }

        if (!response.ok) {
            error.value = 'The payload could not be loaded. Please try again.';

            return;
        }

        // The server decides JSON vs. raw bytes (ADR-024) — the client
        // branches on the response Content-Type it actually got, never on
        // what it asked for.
        const contentType = response.headers.get('Content-Type') ?? '';

        if (contentType.includes('application/json')) {
            const envelope = (await response.json()) as {
                format: 'json';
                document: unknown;
                obfuscated: Record<string, 'default' | 'addition'>;
            };

            format.value = 'json';
            jsonDocument.value = envelope.document;
            jsonObfuscated.value = envelope.obfuscated;
        } else {
            format.value = 'text';
            payload.value = await response.text();
        }

        revealed.value = true;
        announcement.value = 'Payload revealed';
    } catch {
        error.value = 'The payload could not be loaded. Please try again.';
    } finally {
        loading.value = false;
    }
}

function hide(): void {
    revealed.value = false;
    payload.value = '';
    jsonDocument.value = null;
    jsonObfuscated.value = {};
    announcement.value = 'Payload hidden';
}

function toggle(): void {
    if (revealed.value) {
        hide();
    } else {
        void reveal();
    }
}
</script>

<template>
    <div class="grid gap-2">
        <div
            class="max-h-96 overflow-y-auto rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm break-words whitespace-pre-wrap dark:bg-input/30"
        >
            <template v-if="revealed && format === 'json'">
                <template v-for="(part, index) in renderParts" :key="index">
                    <span v-if="part.kind === 'text'" v-text="part.text" />
                    <span
                        v-else
                        class="rounded bg-muted px-1 text-muted-foreground"
                        :title="descriptionFor(part.source)"
                    >
                        <span aria-hidden="true">[Hidden]</span>
                        <span class="sr-only">{{
                            descriptionFor(part.source)
                        }}</span>
                    </span>
                </template>
            </template>
            <span v-else-if="revealed" v-text="payload" />
            <span v-else class="text-muted-foreground italic">
                •••••• hidden — click Reveal to view
            </span>
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <div>
            <Button
                type="button"
                variant="outline"
                :aria-pressed="revealed"
                :disabled="loading"
                @click="toggle"
            >
                <Spinner v-if="loading" />
                <component :is="revealed ? EyeOff : Eye" v-else />
                <span>{{ revealed ? 'Hide payload' : 'Reveal payload' }}</span>
            </Button>
        </div>

        <span aria-live="polite" class="sr-only">{{ announcement }}</span>
    </div>
</template>
