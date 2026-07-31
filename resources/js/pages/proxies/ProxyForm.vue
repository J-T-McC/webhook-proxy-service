<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import DestinationRows from '@/components/DestinationRows.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DestinationRow, ProxyMode } from '@/types/proxies';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    initial: { name: string; mode: ProxyMode; destinations: DestinationRow[] };
}>();

const form = useForm({
    name: props.initial.name,
    mode: props.initial.mode,
    destinations: props.initial.destinations.map((row) => ({ ...row })),
});

const formEl = ref<HTMLFormElement | null>(null);

function submit(): void {
    form.submit(props.method, props.action, {
        preserveScroll: true,
        onError: () => {
            // Move focus to the first field in error (name or a destination row).
            nextTick(() => {
                formEl.value
                    ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus();
            });
        },
    });
}
</script>

<template>
    <form
        ref="formEl"
        class="mx-auto w-full max-w-2xl"
        @submit.prevent="submit"
    >
        <Card class="gap-6 p-6">
            <!-- Details -->
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="Stripe → billing services"
                    :disabled="form.processing"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                    aria-describedby="name-help name-error"
                />
                <p id="name-help" class="text-sm text-muted-foreground">
                    A name to recognise this proxy.
                </p>
                <span id="name-error">
                    <InputError :message="form.errors.name" />
                </span>
            </div>

            <div class="grid gap-2">
                <Label for="mode">Mode</Label>
                <Select v-model="form.mode" :disabled="form.processing">
                    <SelectTrigger id="mode" class="w-full sm:w-64">
                        <SelectValue placeholder="Select a mode" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="simple">Simple</SelectItem>
                        <SelectItem value="enhanced">Enhanced</SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                    Enhanced-mode behaviours (mapping, storage, retries) are not
                    yet functional; Simple delivers the webhook to every
                    destination.
                </p>
                <InputError :message="form.errors.mode" />
            </div>

            <!-- Destinations -->
            <DestinationRows
                v-model="form.destinations"
                :errors="form.errors"
                :disabled="form.processing"
            />
            <InputError :message="form.errors.destinations" />

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ submitLabel }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="cancelHref">Cancel</Link>
                </Button>
            </div>
        </Card>
    </form>
</template>
