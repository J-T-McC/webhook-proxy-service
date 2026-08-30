<script setup lang="ts">
import { Card } from '@/components/ui/card';

const props = defineProps<{
    title: string;
    description: string;
}>();
</script>

<template>
    <Card class="items-center gap-3 p-10 text-center">
        <h2 class="text-lg font-medium">{{ props.title }}</h2>
        <p class="text-sm text-muted-foreground">{{ props.description }}</p>
        <!-- The call to action, if this state has one. Rendered inside the
             card so the spacing matches whether or not it is present. -->
        <slot />
    </Card>
</template>
