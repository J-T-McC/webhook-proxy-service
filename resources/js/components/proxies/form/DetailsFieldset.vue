<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const name = defineModel<string>({ required: true });

const props = defineProps<{
    error?: string;
    disabled?: boolean;
}>();
</script>

<template>
    <Card class="gap-6 p-6">
        <h2 class="text-base font-semibold">Details</h2>
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input
                id="name"
                v-model="name"
                type="text"
                placeholder="Stripe → billing services"
                :disabled="props.disabled"
                :aria-invalid="props.error ? 'true' : undefined"
                aria-describedby="name-error"
            />
            <span id="name-error">
                <InputError :message="props.error" />
            </span>
        </div>
    </Card>
</template>
