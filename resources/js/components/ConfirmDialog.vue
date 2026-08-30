<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

const props = defineProps<{
    open: boolean;
    title: string;
    /**
     * The consequence, stated before the decision. Every caller writes this
     * out in full — a confirmation that does not say what it will do is not
     * a confirmation.
     */
    description: string;
    confirmLabel: string;
    /** Disables the confirm action while the request is in flight. */
    busy?: boolean;
    /** Styles the confirm action as destructive. */
    destructive?: boolean;
}>();

defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();
</script>

<template>
    <AlertDialog
        :open="props.open"
        @update:open="(value) => $emit('update:open', value)"
    >
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ props.title }}</AlertDialogTitle>
                <AlertDialogDescription>
                    {{ props.description }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    :class="
                        props.destructive
                            ? 'bg-destructive text-white hover:bg-destructive/90'
                            : undefined
                    "
                    :disabled="props.busy"
                    @click="$emit('confirm')"
                >
                    {{ props.confirmLabel }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
