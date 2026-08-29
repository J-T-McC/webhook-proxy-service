<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatTimestamp } from '@/lib/format';
import type { DestinationRow } from '@/types/proxies';

const model = defineModel<DestinationRow[]>({ required: true });

const props = defineProps<{
    errors?: Partial<Record<string, string>>;
    disabled?: boolean;
}>();

const urlRefs = ref<HTMLInputElement[]>([]);

function setUrlRef(
    el: Element | ComponentPublicInstance | null,
    index: number,
): void {
    if (el instanceof HTMLInputElement) {
        urlRefs.value[index] = el;
    }
}

type DestinationField =
    'url' | 'http_method' | 'credential_header_name' | 'credential_secret';

function fieldError(
    index: number,
    field: DestinationField,
): string | undefined {
    return props.errors?.[`destinations.${index}.${field}`];
}

function errorId(index: number, field: DestinationField): string {
    return `destination-${index}-${field}-error`;
}

async function addRow(): Promise<void> {
    model.value = [
        ...model.value,
        {
            url: '',
            http_method: 'POST',
            credential_header_name: 'Authorization',
            credential_secret: '',
        },
    ];
    await nextTick();
    urlRefs.value[model.value.length - 1]?.focus();
}

async function removeRow(index: number): Promise<void> {
    if (model.value.length <= 1) {
        return;
    }

    const next = [...model.value];
    next.splice(index, 1);
    model.value = next;

    await nextTick();
    // Focus a sensible neighbour: the previous row's URL (or the first row).
    urlRefs.value[Math.max(0, index - 1)]?.focus();
}

// Screen 3's Credential subsection (T30; AC30, AC33). The write-only shape
// is per-row rather than per-proxy: `credentialIsSet()` governs whether the
// collapsed "Credential set" status line renders or the blank input does,
// and `credential_replacing`/`credential_removed` (kept on the row object
// itself, not a parallel index-keyed structure, so they always travel with
// their row through add/remove) track whether this row's Replace/Remove
// credential has been clicked this session.
function credentialIsSet(row: DestinationRow): boolean {
    return (
        (row.has_credential ?? false) &&
        !row.credential_replacing &&
        !row.credential_removed
    );
}

function startReplacingCredential(row: DestinationRow): void {
    row.credential_replacing = true;
    row.credential_secret = '';
}

// Remove credential (T31; correction B3; plan-10 § Revision A, technical
// ruling 15). Resets this row to the unconfigured in-session presentation —
// header name back to the default, secret status back to unset — exactly
// like an unconfigured row (design-10 Screen 3's states table). Nothing is
// sent to the server until the form saves; `ProxyForm.vue`'s `transform()`
// reads `credential_removed` at submit time to decide the `remove_credential`
// signal, superseding it if the member has since typed a new secret into the
// now-blank field.
function removeCredential(row: DestinationRow): void {
    row.credential_removed = true;
    row.credential_replacing = false;
    row.credential_header_name = 'Authorization';
    row.credential_secret = '';
}

const inputClass =
    'placeholder:text-muted-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20';
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="text-sm font-medium">Destinations</legend>
        <p id="destinations-help" class="text-sm text-muted-foreground">
            The webhook is delivered to every destination below.
        </p>

        <div
            v-for="(row, index) in model"
            :key="index"
            class="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_auto_auto] sm:items-start sm:gap-3"
        >
            <div class="grid gap-1">
                <label :for="`destination-${index}-url`" class="sr-only">
                    Destination {{ index + 1 }} URL
                </label>
                <input
                    :id="`destination-${index}-url`"
                    :ref="(el) => setUrlRef(el, index)"
                    v-model="row.url"
                    type="url"
                    inputmode="url"
                    placeholder="https://example.com/webhook"
                    :class="inputClass"
                    :disabled="disabled"
                    :aria-invalid="
                        fieldError(index, 'url') ? 'true' : undefined
                    "
                    :aria-describedby="
                        fieldError(index, 'url')
                            ? errorId(index, 'url')
                            : 'destinations-help'
                    "
                />
                <div :id="errorId(index, 'url')">
                    <InputError :message="fieldError(index, 'url')" />
                </div>
            </div>

            <div class="grid gap-1">
                <label :for="`destination-${index}-method`" class="sr-only">
                    Destination {{ index + 1 }} method
                </label>
                <Select v-model="row.http_method" :disabled="disabled">
                    <SelectTrigger
                        :id="`destination-${index}-method`"
                        class="w-full sm:w-28"
                        :aria-describedby="
                            fieldError(index, 'http_method')
                                ? errorId(index, 'http_method')
                                : undefined
                        "
                    >
                        <SelectValue placeholder="Method" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="POST">POST</SelectItem>
                        <SelectItem value="PUT">PUT</SelectItem>
                    </SelectContent>
                </Select>
                <div :id="errorId(index, 'http_method')">
                    <InputError :message="fieldError(index, 'http_method')" />
                </div>
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="sm:mt-0.5"
                :disabled="disabled || model.length <= 1"
                :aria-label="`Remove destination ${index + 1}`"
                @click="removeRow(index)"
            >
                <Trash2 />
            </Button>

            <!-- Credential subsection (Screen 3; T30; AC30, AC33) -->
            <Collapsible
                :default-open="row.has_credential === true"
                class="grid gap-2 sm:col-span-3"
            >
                <CollapsibleTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="w-fit"
                        :disabled="disabled"
                    >
                        {{
                            row.has_credential && !row.credential_removed
                                ? 'Credential: set'
                                : 'Add credential'
                        }}
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent
                    class="grid gap-3 rounded-md border border-dashed p-3"
                >
                    <div class="grid gap-1">
                        <label
                            :for="`destination-${index}-credential-header`"
                            class="text-sm font-medium"
                        >
                            Header name
                        </label>
                        <input
                            :id="`destination-${index}-credential-header`"
                            v-model="row.credential_header_name"
                            type="text"
                            placeholder="Authorization"
                            :class="inputClass"
                            :disabled="disabled"
                            :aria-invalid="
                                fieldError(index, 'credential_header_name')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="
                                errorId(index, 'credential_header_name')
                            "
                        />
                        <div :id="errorId(index, 'credential_header_name')">
                            <InputError
                                :message="
                                    fieldError(index, 'credential_header_name')
                                "
                            />
                        </div>
                    </div>

                    <div class="grid gap-1">
                        <label
                            :for="`destination-${index}-credential-secret`"
                            class="text-sm font-medium"
                        >
                            Secret value
                        </label>

                        <template v-if="credentialIsSet(row)">
                            <p class="text-sm">
                                Credential set — changed
                                {{
                                    formatTimestamp(
                                        row.credential_changed_at as string,
                                    )
                                }}
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="w-fit"
                                    :aria-label="`Replace credential for ${row.url}`"
                                    :disabled="disabled"
                                    @click="startReplacingCredential(row)"
                                >
                                    Replace
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="w-fit"
                                    :aria-label="`Remove credential for ${row.url}`"
                                    :disabled="disabled"
                                    @click="removeCredential(row)"
                                >
                                    Remove credential
                                </Button>
                            </div>
                        </template>
                        <template v-else>
                            <input
                                :id="`destination-${index}-credential-secret`"
                                v-model="row.credential_secret"
                                type="password"
                                autocomplete="off"
                                :class="inputClass"
                                :disabled="disabled"
                                :aria-invalid="
                                    fieldError(index, 'credential_secret')
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="
                                    errorId(index, 'credential_secret')
                                "
                            />
                        </template>
                        <div :id="errorId(index, 'credential_secret')">
                            <InputError
                                :message="
                                    fieldError(index, 'credential_secret')
                                "
                            />
                        </div>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        Sent verbatim on every dispatch to this destination —
                        the product adds no scheme prefix (e.g. enter "Bearer
                        abc123" yourself if your destination expects one).
                        Replacing takes effect on the next dispatch — there's no
                        transition period.
                    </p>
                </CollapsibleContent>
            </Collapsible>
        </div>

        <div>
            <Button
                type="button"
                variant="outline"
                :disabled="disabled"
                @click="addRow"
            >
                <Plus />
                <span>Add destination</span>
            </Button>
        </div>
    </fieldset>
</template>
