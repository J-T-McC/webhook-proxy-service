<script setup lang="ts">
import { RefreshCw, RefreshCwOff } from '@lucide/vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    enabled: boolean;
}>();

defineEmits<{
    toggle: [];
}>();
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        size="icon"
        :aria-pressed="props.enabled"
        :aria-label="
            props.enabled ? 'Turn off auto-refresh' : 'Turn on auto-refresh'
        "
        :title="
            props.enabled
                ? 'Auto-refreshing every 5 seconds'
                : 'Auto-refresh is off'
        "
        @click="$emit('toggle')"
    >
        <RefreshCw v-if="props.enabled" class="size-4" />
        <RefreshCwOff v-else class="size-4 text-muted-foreground" />
    </Button>
</template>
