<script setup lang="ts">
import CopyField from '@/components/CopyField.vue';
import { Card } from '@/components/ui/card';

const props = defineProps<{
    ingestUrl: string;
}>();
</script>

<template>
    <Card class="gap-4 p-6">
        <h2 class="text-base font-semibold">Ingest URL</h2>
        <CopyField data-test="ingest-url" :value="props.ingestUrl" />
        <p class="text-sm text-muted-foreground">
            Anyone with this URL can post webhooks to this proxy. Keep it
            secret.
        </p>
    </Card>
</template>
