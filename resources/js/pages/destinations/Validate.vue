<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';

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
    outcome:
        'approvable' | 'approved' | 'already_approved' | 'expired' | 'invalid';
    destinationUrl?: string;
    approveUrl?: string;
    /**
     * The asking team's name (AC27) — the one identifying fact the visitor
     * gets. Absent only on `invalid`, where no resolved challenge exists to
     * name a team from.
     */
    teamName?: string;
}>();

// This page supplies its own layout instance rather than taking the one
// `app.ts` assigns, so that the heading can name the outcome. Assigned there,
// the layout's title is a static prop: every outcome rendered under the
// heading "Approve this destination", including "Destination approved" and
// "This link is not valid", and the page's own heading repeated beneath it as
// a second `h1`.

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

/**
 * The line under the heading. Says what the reader is being asked for, or
 * that nothing is being asked — never a restatement of the heading, and never
 * the approval prompt on an outcome that has already resolved.
 */
const description = computed(() => {
    switch (props.outcome) {
        case 'approvable':
            return 'Confirm that this endpoint should receive webhook events';
        case 'approved':
        case 'already_approved':
            return 'This endpoint receives webhook events';
        case 'expired':
            return 'Nothing was approved';
        default:
            return 'Nothing was changed';
    }
});
</script>

<template>
    <Head :title="heading" />

    <AuthLayout :title="heading" :description="description">
        <div class="space-y-6">
            <template v-if="outcome === 'approvable'">
                <p class="text-sm text-muted-foreground">
                    <span class="font-medium">{{ teamName }}</span> uses this
                    service to relay webhook events to
                    <span class="font-medium wrap-anywhere">{{
                        destinationUrl
                    }}</span
                    >. No events will be sent until somebody approves it here.
                </p>
                <p class="text-sm text-muted-foreground">
                    If you approve, this address starts receiving webhook
                    traffic from {{ teamName }} immediately. If you don't
                    recognise this team or this address, you can safely close
                    this page — nothing happens unless you click Approve below.
                </p>

                <Form
                    :action="approveUrl ?? ''"
                    method="post"
                    v-slot="{ processing }"
                >
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Approve this destination
                    </Button>
                </Form>
            </template>

            <template v-else-if="outcome === 'approved'">
                <p class="text-sm text-muted-foreground">
                    <span class="font-medium wrap-anywhere">{{
                        destinationUrl
                    }}</span>
                    now receives webhook traffic from {{ teamName }}. You can
                    close this page — nothing further is needed here.
                </p>
            </template>

            <template v-else-if="outcome === 'already_approved'">
                <p class="text-sm text-muted-foreground">
                    This destination was already approved and is receiving
                    traffic from {{ teamName }}. There's nothing more to do.
                </p>
            </template>

            <template v-else-if="outcome === 'expired'">
                <p class="text-sm text-muted-foreground">
                    This validation link is no longer active. If {{ teamName }}
                    still needs this destination approved, ask them to send a
                    new one.
                </p>
            </template>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    This link cannot be used. It may have been replaced by a
                    newer one, or the destination may have changed since it was
                    sent. Ask whoever configured the destination to send a new
                    link.
                </p>
            </template>
        </div>
    </AuthLayout>
</template>
