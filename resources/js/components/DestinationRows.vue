<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import {  nextTick, ref } from 'vue';
import type {ComponentPublicInstance} from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export interface DestinationRow {
    id?: number | null;
    url: string;
    http_method: string;
}

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

function fieldError(index: number, field: 'url' | 'http_method'): string | undefined {
    return props.errors?.[`destinations.${index}.${field}`];
}

function errorId(index: number, field: 'url' | 'http_method'): string {
    return `destination-${index}-${field}-error`;
}

async function addRow(): Promise<void> {
    model.value = [...model.value, { url: '', http_method: 'POST' }];
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

const inputClass =
    'placeholder:text-muted-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20';
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="text-sm font-medium">Destinations</legend>
        <p id="destinations-help" class="text-muted-foreground text-sm">
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
                    :aria-invalid="fieldError(index, 'url') ? 'true' : undefined"
                    :aria-describedby="fieldError(index, 'url') ? errorId(index, 'url') : 'destinations-help'"
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
                        :aria-describedby="fieldError(index, 'http_method') ? errorId(index, 'http_method') : undefined"
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
