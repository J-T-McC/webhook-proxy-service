<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * A retained event's masked-by-default payload block with an explicit
 * whole-payload reveal (Flow C; AC25; design-06 §Components). Content is
 * fetched fresh from `url` only on the Reveal click (ADR-017 Decision 6) —
 * never included in the page's initial Inertia props, and never present in
 * this component's own state until the user explicitly asks for it.
 */
const props = defineProps<{
    /** The fetch-on-reveal payload endpoint URL (T28's `GET .../payload`). */
    url: string;
}>();

const revealed = ref(false);
const loading = ref(false);
const payload = ref('');
const error = ref('');
const announcement = ref('');

async function reveal(): Promise<void> {
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch(props.url, {
            headers: { Accept: 'text/plain' },
        });

        if (response.status === 410) {
            error.value =
                'This payload has expired since the page loaded and can no longer be viewed.';

            return;
        }

        if (!response.ok) {
            error.value = 'The payload could not be loaded. Please try again.';

            return;
        }

        payload.value = await response.text();
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
            <span v-if="revealed" v-text="payload" />
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
