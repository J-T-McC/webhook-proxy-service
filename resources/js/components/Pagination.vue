<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { PaginationLink } from '@/types/proxies';

const props = defineProps<{
    /** The paginator's own link set, rendered verbatim in server order. */
    links: PaginationLink[];
    lastPage: number;
}>();
</script>

<template>
    <nav
        v-if="props.lastPage > 1"
        class="flex flex-wrap gap-1"
        aria-label="Pagination"
    >
        <Button
            v-for="link in props.links"
            :key="link.label"
            :variant="link.active ? 'default' : 'outline'"
            size="sm"
            :disabled="!link.url"
            :aria-current="link.active ? 'page' : undefined"
            @click="link.url && router.get(link.url)"
        >
            <span v-html="link.label" />
        </Button>
    </nav>
</template>
