<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { highlight } from '@/lib/highlighter';
import type { CodeLanguage } from '@/lib/highlighter';

const props = defineProps<{
    code: string;
    /** `text` skips Shiki entirely — no grammar chunk, line numbers only. */
    lang: CodeLanguage | 'text';
    /** Shown in the frame's title bar. Defaults to the language name. */
    label?: string;
}>();

// Rendered plain first, replaced once Shiki has loaded. A reader who never
// gets the JavaScript still gets the code.
const highlighted = ref<string | null>(null);

onMounted(async () => {
    if (props.lang === 'text') {
        return;
    }

    highlighted.value = await highlight(props.code, props.lang);
});

const lines = computed(() => props.code.split('\n'));
</script>

<template>
    <figure
        class="overflow-hidden rounded-md border border-border bg-muted/40 dark:bg-[#0d1117]"
    >
        <figcaption
            class="flex items-center gap-2 border-b border-border px-3 py-1.5 text-xs text-muted-foreground"
        >
            <span class="flex gap-1.5" aria-hidden="true">
                <span class="size-2.5 rounded-full bg-border" />
                <span class="size-2.5 rounded-full bg-border" />
                <span class="size-2.5 rounded-full bg-border" />
            </span>
            {{ props.label ?? props.lang }}
        </figcaption>

        <!-- eslint-disable-next-line vue/no-v-html -- Shiki output, built
             from `code` on this page, never from user input. -->
        <div v-if="highlighted" class="code" v-html="highlighted" />
        <div v-else class="code">
            <pre
                class="shiki"
            ><code><span v-for="(line, index) in lines" :key="index" class="line">{{ line }}
</span></code></pre>
        </div>
    </figure>
</template>

<style scoped>
/* Shiki emits a `.line` span per line, which is what the line numbers count. */
.code :deep(.shiki) {
    overflow-x: auto;
    padding: 1rem 1rem 1rem 0;
    font-size: 0.75rem;
    line-height: 1.6;
    background-color: transparent !important;
}

.code :deep(.shiki code) {
    display: grid;
    counter-reset: line;
}

.code :deep(.shiki .line)::before {
    counter-increment: line;
    content: counter(line);
    display: inline-block;
    width: 2.5rem;
    margin-right: 1rem;
    text-align: right;
    color: var(--muted-foreground);
    opacity: 0.6;
    user-select: none;
}

/* Dual-theme output: `--shiki-light` paints by default, `--shiki-dark` once
   the document carries the dark class. */
.code :deep(.shiki),
.code :deep(.shiki span) {
    color: var(--shiki-light);
}

html.dark .code :deep(.shiki),
html.dark .code :deep(.shiki span) {
    color: var(--shiki-dark);
}
</style>
