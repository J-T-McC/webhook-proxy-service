<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * The destination-approval page (#18, design-18 Screen 4).
 *
 * Seen by somebody with **no account** who arrived from a link posted to their
 * own webhook endpoint. They have no context beyond what is on this page, so
 * each outcome states plainly what happened, what it means, and whether
 * anything is now expected of them.
 *
 * Four distinct outcomes rather than one screen with a variable message,
 * because the reader's next action differs in each: approve, nothing to do,
 * ask for a new link, or this link is not usable.
 */
const props = defineProps<{
    outcome: 'approvable' | 'approved' | 'already_approved' | 'expired' | 'invalid';
    destinationUrl?: string;
    approveUrl?: string;
}>();

defineOptions({
    layout: {
        title: 'Approve this destination',
        description: 'Confirm that this endpoint should receive webhook events',
    },
});

const heading = computed(() => {
    switch (props.outcome) {
        case 'approvable':
            return 'Approve this destination?';
        case 'approved':
            return 'Destination approved';
        case 'already_approved':
            return 'Already approved';
        case 'expired':
            return 'This link has expired';
        default:
            return 'This link is not valid';
    }
});
</script>

<template>
    <Head :title="heading" />

    <div class="space-y-6">
        <h1 class="text-xl font-semibold">{{ heading }}</h1>

        <template v-if="outcome === 'approvable'">
            <p class="text-sm text-muted-foreground">
                A webhook proxy has been configured to send events to
                <span class="font-medium break-all">{{ destinationUrl }}</span
                >. No events will be sent until somebody approves it here.
            </p>
            <p class="text-sm text-muted-foreground">
                Approve only if you expected this. If you did not, close this
                page — nothing will be sent.
            </p>

            <Form :action="approveUrl ?? ''" method="post" v-slot="{ processing }">
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Approve this destination
                </Button>
            </Form>
        </template>

        <template v-else-if="outcome === 'approved'">
            <p class="text-sm text-muted-foreground">
                <span class="font-medium break-all">{{ destinationUrl }}</span>
                will now receive webhook events. You can close this page.
            </p>
        </template>

        <template v-else-if="outcome === 'already_approved'">
            <p class="text-sm text-muted-foreground">
                This destination was already approved and is receiving events.
                Nothing further is needed.
            </p>
        </template>

        <template v-else-if="outcome === 'expired'">
            <p class="text-sm text-muted-foreground">
                Approval links are valid for a limited time and this one has
                passed it. Ask whoever configured the destination to send a new
                one.
            </p>
        </template>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                This link cannot be used. It may have been replaced by a newer
                one, or the destination may have changed since it was sent. Ask
                whoever configured the destination to send a new link.
            </p>
        </template>
    </div>
</template>
