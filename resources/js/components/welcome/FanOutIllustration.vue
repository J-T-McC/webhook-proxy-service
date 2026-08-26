<script setup lang="ts">
// Illustration 1 (design-landing-page.md, corrected 2026-08-25): one inbound
// webhook event fanned out to three destinations — Async (parallel, no
// cross-event serialization) vs FIFO (at most one event in flight per proxy
// at a time). Ordering/exclusivity is EVENT-level, not per-destination —
// see the spec's Correction note and Factual Audit section, ADR-011 §38/§130,
// and AdvanceProxyFifoQueue's docblock. Two independent, simultaneously-
// visible panels, each rendered as a horizontal layout (>= sm) and a
// vertical layout (< sm), each in turn carrying a motion-safe animated scene
// and a motion-reduce static scene. Decorative only — every fact is also
// carried by the always-visible caption below each panel, per the spec's
// Accessibility section.
</script>

<template>
    <div class="grid gap-10 lg:grid-cols-2">
        <!-- Panel A — Async: events overlap, no cross-event serialization -->
        <figure>
            <!-- Horizontal layout (sm and up) -->
            <svg
                viewBox="0 0 460 220"
                preserveAspectRatio="xMidYMid meet"
                aria-hidden="true"
                class="hidden w-full sm:block"
                style="
                    --dx-j: 150px;
                    --dy-j: 0px;
                    --dx-d1: 132px;
                    --dy-d1: -79px;
                    --dx-d2: 132px;
                    --dy-d2: 0px;
                    --dx-d3: 132px;
                    --dy-d3: 79px;
                "
            >
                <g
                    class="fill-none stroke-border"
                    stroke-width="2"
                    stroke-linecap="round"
                >
                    <line x1="88" y1="110" x2="231" y2="110" />
                    <line x1="249" y1="110" x2="372" y2="31" />
                    <line x1="249" y1="110" x2="372" y2="110" />
                    <line x1="249" y1="110" x2="372" y2="189" />
                </g>
                <g class="fill-card stroke-border" stroke-width="2">
                    <rect x="12" y="95" width="76" height="30" rx="6" />
                    <circle cx="240" cy="110" r="9" />
                    <rect x="372" y="16" width="76" height="30" rx="6" />
                    <rect x="372" y="95" width="76" height="30" rx="6" />
                    <rect x="372" y="174" width="76" height="30" rx="6" />
                </g>
                <g class="fill-foreground text-[10px]" text-anchor="middle">
                    <text x="50" y="114">Webhook</text>
                    <text x="410" y="35">1</text>
                    <text x="410" y="114">2</text>
                    <text x="410" y="193">3</text>
                </g>

                <!-- Motion-safe: three staggered, overlapping events -->
                <g class="motion-reduce:hidden">
                    <rect
                        x="372"
                        y="16"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-flash-async-multi fill-primary"
                    />
                    <rect
                        x="372"
                        y="95"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-flash-async-multi fill-primary"
                    />
                    <rect
                        x="372"
                        y="174"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-flash-async-multi fill-primary"
                    />

                    <circle
                        cx="90"
                        cy="110"
                        r="6"
                        class="fanout-async-main fanout-delay-1 fill-primary"
                    />
                    <circle
                        cx="90"
                        cy="110"
                        r="6"
                        class="fanout-async-main fanout-delay-2 fill-primary"
                    />
                    <circle
                        cx="90"
                        cy="110"
                        r="6"
                        class="fanout-async-main fanout-delay-3 fill-primary"
                    />

                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fanout-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                </g>

                <!-- Reduced-motion: two events visible at once, at different stages -->
                <g class="hidden motion-reduce:block">
                    <rect
                        x="372"
                        y="16"
                        width="76"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="372"
                        y="95"
                        width="76"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="372"
                        y="174"
                        width="76"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <circle cx="410" cy="31" r="6" class="fill-primary" />
                    <circle cx="410" cy="110" r="6" class="fill-primary" />
                    <circle cx="410" cy="189" r="6" class="fill-primary" />
                    <circle cx="160" cy="110" r="6" class="fill-primary" />
                </g>
            </svg>

            <!-- Vertical layout (below sm) -->
            <svg
                viewBox="0 0 220 380"
                preserveAspectRatio="xMidYMid meet"
                aria-hidden="true"
                class="w-full sm:hidden"
                style="
                    --dx-j: 0px;
                    --dy-j: 40px;
                    --dx-d1: -72px;
                    --dy-d1: 92px;
                    --dx-d2: 0px;
                    --dy-d2: 92px;
                    --dx-d3: 72px;
                    --dy-d3: 92px;
                "
            >
                <g
                    class="fill-none stroke-border"
                    stroke-width="2"
                    stroke-linecap="round"
                >
                    <line x1="110" y1="38" x2="110" y2="69" />
                    <line x1="110" y1="87" x2="38" y2="170" />
                    <line x1="110" y1="87" x2="110" y2="170" />
                    <line x1="110" y1="87" x2="182" y2="170" />
                </g>
                <g class="fill-card stroke-border" stroke-width="2">
                    <rect x="72" y="10" width="76" height="28" rx="6" />
                    <circle cx="110" cy="78" r="9" />
                    <rect x="8" y="170" width="60" height="30" rx="6" />
                    <rect x="80" y="170" width="60" height="30" rx="6" />
                    <rect x="152" y="170" width="60" height="30" rx="6" />
                </g>
                <g class="fill-foreground text-[9px]" text-anchor="middle">
                    <text x="110" y="28">Webhook</text>
                    <text x="38" y="189">1</text>
                    <text x="110" y="189">2</text>
                    <text x="182" y="189">3</text>
                </g>

                <g class="motion-reduce:hidden">
                    <rect
                        x="8"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-flash-async-multi fill-primary"
                    />
                    <rect
                        x="80"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-flash-async-multi fill-primary"
                    />
                    <rect
                        x="152"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-flash-async-multi fill-primary"
                    />

                    <circle
                        cx="110"
                        cy="38"
                        r="6"
                        class="fanout-async-main fanout-delay-1 fill-primary"
                    />
                    <circle
                        cx="110"
                        cy="38"
                        r="6"
                        class="fanout-async-main fanout-delay-2 fill-primary"
                    />
                    <circle
                        cx="110"
                        cy="38"
                        r="6"
                        class="fanout-async-main fanout-delay-3 fill-primary"
                    />

                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fanout-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                </g>

                <g class="hidden motion-reduce:block">
                    <rect
                        x="8"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="80"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="152"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <circle cx="38" cy="185" r="6" class="fill-primary" />
                    <circle cx="110" cy="185" r="6" class="fill-primary" />
                    <circle cx="182" cy="185" r="6" class="fill-primary" />
                    <circle cx="110" cy="53" r="6" class="fill-primary" />
                </g>
            </svg>

            <figcaption class="mt-3 text-sm text-muted-foreground">
                Async — every destination receives it at once.
            </figcaption>
        </figure>

        <!-- Panel B — FIFO: at most one event in flight per proxy at a time -->
        <figure>
            <svg
                viewBox="0 0 460 220"
                preserveAspectRatio="xMidYMid meet"
                aria-hidden="true"
                class="hidden w-full sm:block"
                style="
                    --dx-j: 150px;
                    --dy-j: 0px;
                    --dx-d1: 132px;
                    --dy-d1: -79px;
                    --dx-d2: 132px;
                    --dy-d2: 0px;
                    --dx-d3: 132px;
                    --dy-d3: 79px;
                "
            >
                <g
                    class="fill-none stroke-border"
                    stroke-width="2"
                    stroke-linecap="round"
                >
                    <line x1="88" y1="110" x2="231" y2="110" />
                    <line x1="249" y1="110" x2="372" y2="31" />
                    <line x1="249" y1="110" x2="372" y2="110" />
                    <line x1="249" y1="110" x2="372" y2="189" />
                </g>
                <g class="fill-card stroke-border" stroke-width="2">
                    <rect x="12" y="95" width="76" height="30" rx="6" />
                    <circle cx="240" cy="110" r="9" />
                    <rect x="372" y="16" width="76" height="30" rx="6" />
                    <rect x="372" y="95" width="76" height="30" rx="6" />
                    <rect x="372" y="174" width="76" height="30" rx="6" />
                </g>
                <g class="fill-foreground text-[10px]" text-anchor="middle">
                    <text x="50" y="114">Webhook</text>
                    <text x="410" y="35">1</text>
                    <text x="410" y="114">2</text>
                    <text x="410" y="193">3</text>
                </g>

                <!-- Motion-safe: one event in flight at a time, two queued at Ingest -->
                <g class="motion-reduce:hidden">
                    <rect
                        x="372"
                        y="16"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-multi fill-primary"
                    />
                    <rect
                        x="372"
                        y="95"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-multi fill-primary"
                    />
                    <rect
                        x="372"
                        y="174"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-multi fill-primary"
                    />

                    <!-- Event 1: departs immediately, no queueing -->
                    <circle
                        cx="90"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-main fill-primary"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />

                    <!-- Event 2: queued (muted, static) at Ingest until 30%, then departs -->
                    <circle
                        cx="90"
                        cy="96"
                        r="5"
                        class="fanout-fifo-queued-2"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />

                    <!-- Event 3: queued (muted, static) at Ingest until 60%, then departs -->
                    <circle
                        cx="90"
                        cy="124"
                        r="5"
                        class="fanout-fifo-queued-3"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                </g>

                <!-- Reduced-motion: one event delivered, two queued at Ingest with order badges -->
                <g class="hidden motion-reduce:block">
                    <rect
                        x="372"
                        y="16"
                        width="76"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="372"
                        y="95"
                        width="76"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="372"
                        y="174"
                        width="76"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <circle cx="410" cy="31" r="6" class="fill-primary" />
                    <circle cx="410" cy="110" r="6" class="fill-primary" />
                    <circle cx="410" cy="189" r="6" class="fill-primary" />

                    <circle
                        cx="90"
                        cy="96"
                        r="5"
                        class="fill-muted-foreground"
                    />
                    <text
                        x="90"
                        y="84"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[9px]"
                    >
                        2
                    </text>
                    <circle
                        cx="90"
                        cy="124"
                        r="5"
                        class="fill-muted-foreground"
                    />
                    <text
                        x="90"
                        y="140"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[9px]"
                    >
                        3
                    </text>
                </g>
            </svg>

            <svg
                viewBox="0 0 220 380"
                preserveAspectRatio="xMidYMid meet"
                aria-hidden="true"
                class="w-full sm:hidden"
                style="
                    --dx-j: 0px;
                    --dy-j: 40px;
                    --dx-d1: -72px;
                    --dy-d1: 92px;
                    --dx-d2: 0px;
                    --dy-d2: 92px;
                    --dx-d3: 72px;
                    --dy-d3: 92px;
                "
            >
                <g
                    class="fill-none stroke-border"
                    stroke-width="2"
                    stroke-linecap="round"
                >
                    <line x1="110" y1="38" x2="110" y2="69" />
                    <line x1="110" y1="87" x2="38" y2="170" />
                    <line x1="110" y1="87" x2="110" y2="170" />
                    <line x1="110" y1="87" x2="182" y2="170" />
                </g>
                <g class="fill-card stroke-border" stroke-width="2">
                    <rect x="72" y="10" width="76" height="28" rx="6" />
                    <circle cx="110" cy="78" r="9" />
                    <rect x="8" y="170" width="60" height="30" rx="6" />
                    <rect x="80" y="170" width="60" height="30" rx="6" />
                    <rect x="152" y="170" width="60" height="30" rx="6" />
                </g>
                <g class="fill-foreground text-[9px]" text-anchor="middle">
                    <text x="110" y="28">Webhook</text>
                    <text x="38" y="189">1</text>
                    <text x="110" y="189">2</text>
                    <text x="182" y="189">3</text>
                </g>

                <g class="motion-reduce:hidden">
                    <rect
                        x="8"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-multi fill-primary"
                    />
                    <rect
                        x="80"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-multi fill-primary"
                    />
                    <rect
                        x="152"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-multi fill-primary"
                    />

                    <circle
                        cx="110"
                        cy="38"
                        r="6"
                        class="fanout-fifo-event-main fill-primary"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-1 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />

                    <circle
                        cx="96"
                        cy="38"
                        r="5"
                        class="fanout-fifo-queued-2"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-2 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />

                    <circle
                        cx="124"
                        cy="38"
                        r="5"
                        class="fanout-fifo-queued-3"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-event-branch fanout-fifo-delay-3 fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                </g>

                <g class="hidden motion-reduce:block">
                    <rect
                        x="8"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="80"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <rect
                        x="152"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fill-primary/10"
                    />
                    <circle cx="38" cy="185" r="6" class="fill-primary" />
                    <circle cx="110" cy="185" r="6" class="fill-primary" />
                    <circle cx="182" cy="185" r="6" class="fill-primary" />

                    <circle
                        cx="96"
                        cy="38"
                        r="5"
                        class="fill-muted-foreground"
                    />
                    <text
                        x="96"
                        y="26"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[9px]"
                    >
                        2
                    </text>
                    <circle
                        cx="124"
                        cy="38"
                        r="5"
                        class="fill-muted-foreground"
                    />
                    <text
                        x="124"
                        y="26"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[9px]"
                    >
                        3
                    </text>
                </g>
            </svg>

            <figcaption class="mt-3 text-sm text-muted-foreground">
                FIFO — one event at a time per proxy, processed in the order
                received.
            </figcaption>
        </figure>
    </div>
</template>

<style scoped>
/* Panel A — Async: three events, staggered by 20% of a 3.6s loop each, so
   they visibly overlap in flight — no cross-event serialization. */
@keyframes fanout-async-main {
    0% {
        opacity: 1;
        transform: translate(0, 0);
    }
    15% {
        opacity: 1;
        transform: translate(var(--dx-j), var(--dy-j));
    }
    15.5%,
    100% {
        opacity: 0;
    }
}
.fanout-async-main {
    animation: fanout-async-main 3.6s linear infinite;
}

@keyframes fanout-async-branch {
    0%,
    14.9% {
        opacity: 0;
        transform: translate(0, 0);
    }
    15% {
        opacity: 1;
        transform: translate(0, 0);
    }
    35% {
        opacity: 1;
        transform: translate(var(--dx-d), var(--dy-d));
    }
    40% {
        opacity: 1;
        transform: translate(var(--dx-d), var(--dy-d));
    }
    45%,
    100% {
        opacity: 0;
        transform: translate(var(--dx-d), var(--dy-d));
    }
}
.fanout-async-branch {
    animation: fanout-async-branch 3.6s linear infinite;
}

.fanout-delay-1 {
    animation-delay: 0s;
}
.fanout-delay-2 {
    animation-delay: 0.72s;
}
.fanout-delay-3 {
    animation-delay: 1.44s;
}

@keyframes fanout-flash-async {
    0%,
    34.9% {
        opacity: 0;
    }
    35% {
        opacity: 1;
    }
    38% {
        opacity: 1;
    }
    39.5%,
    100% {
        opacity: 0;
    }
}
.fanout-flash-async-multi {
    opacity: 0;
    animation:
        fanout-flash-async 3.6s linear infinite,
        fanout-flash-async 3.6s linear infinite,
        fanout-flash-async 3.6s linear infinite;
    animation-delay: 0s, 0.72s, 1.44s;
}

/* Panel B — FIFO: at most one event in flight per proxy at a time. Event 1
   departs immediately; Events 2/3 sit queued (muted, static) at Ingest until
   their turn, one 5.4s loop. */
@keyframes fanout-fifo-event-main {
    0% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    10% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(var(--dx-j), var(--dy-j));
    }
    10.5%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-event-main {
    animation: fanout-fifo-event-main 5.4s linear infinite;
}

@keyframes fanout-fifo-event-branch {
    0%,
    9.9% {
        opacity: 0;
        transform: translate(0, 0);
    }
    10% {
        opacity: 1;
        transform: translate(0, 0);
    }
    25% {
        opacity: 1;
        transform: translate(var(--dx-d), var(--dy-d));
    }
    28% {
        opacity: 1;
        transform: translate(var(--dx-d), var(--dy-d));
    }
    30%,
    100% {
        opacity: 0;
        transform: translate(var(--dx-d), var(--dy-d));
    }
}
.fanout-fifo-event-branch {
    animation: fanout-fifo-event-branch 5.4s linear infinite;
}

.fanout-fifo-delay-1 {
    animation-delay: 0s;
}
.fanout-fifo-delay-2 {
    animation-delay: 1.62s;
}
.fanout-fifo-delay-3 {
    animation-delay: 3.24s;
}

@keyframes fanout-fifo-queued-2 {
    0%,
    29.9% {
        opacity: 1;
        fill: var(--color-muted-foreground);
        transform: translate(0, 0);
    }
    30% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    40% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(var(--dx-j), var(--dy-j));
    }
    40.5%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-queued-2 {
    animation: fanout-fifo-queued-2 5.4s linear infinite;
}

@keyframes fanout-fifo-queued-3 {
    0%,
    59.9% {
        opacity: 1;
        fill: var(--color-muted-foreground);
        transform: translate(0, 0);
    }
    60% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    70% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(var(--dx-j), var(--dy-j));
    }
    70.5%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-queued-3 {
    animation: fanout-fifo-queued-3 5.4s linear infinite;
}

@keyframes fanout-fifo-flash {
    0%,
    24.9% {
        opacity: 0;
    }
    25% {
        opacity: 1;
    }
    27.5% {
        opacity: 1;
    }
    28.7%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-flash-multi {
    opacity: 0;
    animation:
        fanout-fifo-flash 5.4s linear infinite,
        fanout-fifo-flash 5.4s linear infinite,
        fanout-fifo-flash 5.4s linear infinite;
    animation-delay: 0s, 1.62s, 3.24s;
}

@media (prefers-reduced-motion: reduce) {
    .fanout-async-main,
    .fanout-async-branch,
    .fanout-flash-async-multi,
    .fanout-fifo-event-main,
    .fanout-fifo-event-branch,
    .fanout-fifo-queued-2,
    .fanout-fifo-queued-3,
    .fanout-fifo-flash-multi {
        animation: none;
    }
}
</style>
