<script setup lang="ts">
import { Info, X } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Screen 2 (AC12, AC13, AC19, C4, N4). No enable/disable control exists
 * anywhere here: obfuscation is always on (N4).
 */
const fields = defineModel<string[]>({ required: true });

const props = defineProps<{
    /** The fixed AC12 default list (T11) — rendered literally, one badge per
     * entry, never summarised (Screen 2, correction C4). */
    defaultNames: string[];
    error?: string;
    disabled?: boolean;
}>();

const sensitiveFieldInput = ref('');

function addSensitiveField(): void {
    const name = sensitiveFieldInput.value.trim();

    if (name === '') {
        return;
    }

    // A duplicate-of-an-existing-addition entry is a silent no-op — no
    // error toast, matching this app's low-ceremony treatment (Screen 2
    // "Duplicate/empty entry" state). A name that also happens to match a
    // default is still accepted here (harmless — AC12/AC13 never conflict);
    // the server is the authority on de-duplication by normalised form.
    if (
        fields.value.some(
            (existing) => existing.trim().toLowerCase() === name.toLowerCase(),
        )
    ) {
        sensitiveFieldInput.value = '';

        return;
    }

    fields.value = [...fields.value, name];
    sensitiveFieldInput.value = '';
}

function removeSensitiveField(index: number): void {
    const next = [...fields.value];
    next.splice(index, 1);
    fields.value = next;
}
</script>

<template>
    <Card class="gap-6 p-6">
        <fieldset class="grid gap-4">
            <legend class="text-base font-semibold">Sensitive fields</legend>
            <p class="text-sm text-muted-foreground">
                Hidden wherever this proxy's payloads are shown. Storage and
                delivery are unaffected.
            </p>

            <div class="grid gap-2">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-medium">Always hidden</p>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="More about matching for Always hidden fields"
                            >
                                <Info class="size-3.5" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-xs">
                            <p>
                                Matches password, Password, pass_word, etc. —
                                case and separators don't matter.
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="name in props.defaultNames"
                        :key="name"
                        variant="secondary"
                    >
                        {{ name }}
                    </Badge>
                </div>
            </div>

            <div class="grid gap-2">
                <p class="text-sm font-medium">Also hidden for this proxy</p>
                <div v-if="fields.length > 0" class="flex flex-wrap gap-2">
                    <Badge
                        v-for="(name, index) in fields"
                        :key="`${name}-${index}`"
                        variant="outline"
                        class="gap-1 pr-1.5"
                    >
                        {{ name }}
                        <button
                            type="button"
                            class="rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-label="`Remove ${name}`"
                            :disabled="props.disabled"
                            @click="removeSensitiveField(index)"
                        >
                            <X class="size-3" />
                        </button>
                    </Badge>
                </div>

                <div class="flex gap-2">
                    <div class="grid flex-1 gap-1">
                        <Label for="sensitive-field-add" class="sr-only">
                            Add field name
                        </Label>
                        <Input
                            id="sensitive-field-add"
                            v-model="sensitiveFieldInput"
                            type="text"
                            placeholder="e.g. ssn_last4"
                            :disabled="props.disabled"
                            aria-describedby="sensitive-fields-error"
                            @keydown.enter.prevent="addSensitiveField"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="props.disabled"
                        @click="addSensitiveField"
                    >
                        Add
                    </Button>
                </div>
                <span id="sensitive-fields-error">
                    <InputError :message="props.error" />
                </span>
            </div>
        </fieldset>
    </Card>
</template>
