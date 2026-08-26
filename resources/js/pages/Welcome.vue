<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ListOrdered, Split, Webhook } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import FanOutIllustration from '@/components/welcome/FanOutIllustration.vue';
import ReliabilityIllustration from '@/components/welcome/ReliabilityIllustration.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const howItWorks = [
    {
        icon: Webhook,
        title: 'Ingest',
        body: 'Create a proxy and get a unique ingest URL. Point any webhook sender at it — no changes needed on their end.',
    },
    {
        icon: Split,
        title: 'Fan out',
        body: "Every request that arrives is delivered to all of that proxy's destinations, in the same structure, however many you've configured.",
    },
    {
        icon: ListOrdered,
        title: 'Choose your processing',
        body: 'Async dispatches to every destination in parallel, for the highest throughput. FIFO processes one event at a time per proxy, in the order it was received, before starting the next — the right choice when strict ordering matters more than throughput.',
    },
];

const reliabilitySteps = [
    {
        title: 'Delivery attempted',
        body: "Sent to the destination the moment it's due.",
    },
    {
        title: 'Retried automatically',
        body: 'A failure is retried after a short wait, then a longer one, up to a set limit.',
    },
    {
        title: 'Marked terminally failed',
        body: 'Once retries are exhausted, the delivery is marked clearly — never hidden or silently dropped.',
    },
    {
        title: 'Replayed on demand',
        body: "Any delivery, including a terminally failed one, can be sent again manually to some or all of the proxy's destinations.",
    },
];
</script>

<template>
    <Head title="Webhook Proxy Service" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="mx-auto max-w-6xl px-6 py-6">
            <nav class="flex items-center justify-end gap-4 text-sm">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboardUrl"
                    class="inline-block rounded-sm border border-transparent px-5 py-1.5 leading-normal text-foreground hover:border-border"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 leading-normal text-foreground hover:border-border"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="inline-block rounded-sm border border-border px-5 py-1.5 leading-normal text-foreground hover:bg-accent"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>

        <main>
            <!-- Hero -->
            <section class="mx-auto max-w-6xl px-6 pt-6 pb-16 lg:pb-20">
                <p class="text-sm font-medium text-muted-foreground">
                    Webhook Proxy Service
                </p>
                <h1
                    class="mt-3 max-w-3xl text-4xl font-semibold tracking-tight text-balance lg:text-5xl"
                >
                    Ingest once. Deliver everywhere.
                </h1>
                <p class="mt-4 max-w-2xl text-lg text-muted-foreground">
                    One webhook in, fanned out to every destination you
                    configure — automatically retried on failure, and replayable
                    on demand, with full visibility into every delivery attempt.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <Button v-if="$page.props.auth.user" as-child size="lg">
                        <Link :href="dashboardUrl">Go to dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button as-child size="lg">
                            <Link :href="register()">Register</Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <Link :href="login()">Log in</Link>
                        </Button>
                    </template>
                </div>

                <FanOutIllustration class="mt-16" />
            </section>

            <!-- How it works -->
            <section class="mx-auto max-w-6xl px-6 py-16 lg:py-20">
                <h2 class="text-2xl font-semibold tracking-tight">
                    How it works
                </h2>
                <div class="mt-10 grid gap-10 md:grid-cols-3">
                    <div v-for="step in howItWorks" :key="step.title">
                        <component
                            :is="step.icon"
                            class="size-6 text-primary"
                            aria-hidden="true"
                        />
                        <h3 class="mt-4 text-sm font-medium">
                            {{ step.title }}
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ step.body }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- Reliability -->
            <section class="mx-auto max-w-6xl px-6 py-16 lg:py-20">
                <h2
                    class="max-w-2xl text-2xl font-semibold tracking-tight text-balance"
                >
                    Nothing gets lost, even when a destination is down.
                </h2>
                <p class="mt-4 max-w-2xl text-muted-foreground">
                    Every delivery to every destination is tracked on its own.
                    When one fails, it's retried automatically on a bounded
                    backoff — waiting a little longer each time — instead of
                    hammering a struggling endpoint or giving up immediately.
                </p>

                <div class="mt-10 grid gap-10 lg:grid-cols-2 lg:items-center">
                    <ol class="space-y-6">
                        <li
                            v-for="(step, index) in reliabilitySteps"
                            :key="step.title"
                            class="flex gap-4"
                        >
                            <span
                                class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground"
                            >
                                {{ index + 1 }}
                            </span>
                            <div>
                                <h3 class="text-sm font-medium">
                                    {{ step.title }}
                                </h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ step.body }}
                                </p>
                            </div>
                        </li>
                    </ol>

                    <ReliabilityIllustration />
                </div>
            </section>
        </main>
    </div>
</template>
