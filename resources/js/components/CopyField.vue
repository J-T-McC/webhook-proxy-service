<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        value: string;
        copyLabel?: string;
        announcement?: string;
        class?: string;
    }>(),
    {
        copyLabel: 'Copy ingest URL',
        announcement: 'Ingest URL copied to clipboard',
        class: undefined,
    },
);

const copied = ref(false);
const announced = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
    } catch {
        // Clipboard blocked/unavailable: the value is selectable read-only text,
        // so the user can still select-and-copy manually (no bespoke fallback).
    }

    copied.value = true;
    announced.value = props.announcement;

    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        copied.value = false;
        announced.value = '';
    }, 2000);
}

function selectAll(event: FocusEvent): void {
    (event.target as HTMLInputElement).select();
}

const inputClass =
    'placeholder:text-muted-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
</script>

<template>
    <div :class="cn('flex items-center gap-2', props.class)">
        <input
            :value="value"
            type="text"
            readonly
            :class="inputClass"
            @focus="selectAll"
        />
        <Button
            type="button"
            variant="outline"
            :aria-label="copyLabel"
            @click="copy"
        >
            <component :is="copied ? Check : Copy" />
            <span>{{ copied ? 'Copied' : 'Copy' }}</span>
        </Button>
        <span aria-live="polite" class="sr-only">{{ announced }}</span>
    </div>
</template>
