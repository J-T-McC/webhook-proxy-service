<script setup lang="ts">
// Illustration 1 (design-landing-page.md): one inbound webhook fanned out to
// three destinations — Async (parallel) vs FIFO (strictly ordered, one at a
// time). Two independent, simultaneously-visible panels, each rendered as a
// horizontal layout (>= sm) and a vertical layout (< sm), each in turn
// carrying a motion-safe animated scene and a motion-reduce static scene.
// Decorative only — every fact is also carried by the always-visible caption
// below each panel, per the spec's Accessibility section.
</script>

<template>
    <div class="grid gap-10 lg:grid-cols-2">
        <!-- Panel A — Async -->
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

                <!-- Motion-safe animated scene -->
                <g class="motion-reduce:hidden">
                    <rect
                        x="372"
                        y="16"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-flash-async fill-primary"
                    />
                    <rect
                        x="372"
                        y="95"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-flash-async fill-primary"
                    />
                    <rect
                        x="372"
                        y="174"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-flash-async fill-primary"
                    />
                    <circle
                        cx="90"
                        cy="110"
                        r="6"
                        class="fanout-async-main fill-primary"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-async-branch fill-primary"
                        style="--dx-d: var(--dx-d3); --dy-d: var(--dy-d3)"
                    />
                </g>

                <!-- Reduced-motion static fallback: all three "delivered" at once -->
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
                        class="fanout-flash-async fill-primary"
                    />
                    <rect
                        x="80"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-flash-async fill-primary"
                    />
                    <rect
                        x="152"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-flash-async fill-primary"
                    />
                    <circle
                        cx="110"
                        cy="38"
                        r="6"
                        class="fanout-async-main fill-primary"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fill-primary"
                        style="--dx-d: var(--dx-d1); --dy-d: var(--dy-d1)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fill-primary"
                        style="--dx-d: var(--dx-d2); --dy-d: var(--dy-d2)"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-async-branch fill-primary"
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
                </g>
            </svg>

            <figcaption class="mt-3 text-sm text-muted-foreground">
                Async — every destination receives it at once.
            </figcaption>
        </figure>

        <!-- Panel B — FIFO -->
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

                <g class="motion-reduce:hidden">
                    <rect
                        x="372"
                        y="16"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-1 fill-primary"
                    />
                    <rect
                        x="372"
                        y="95"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-2 fill-primary"
                    />
                    <rect
                        x="372"
                        y="174"
                        width="76"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-3 fill-primary"
                    />
                    <circle
                        cx="90"
                        cy="110"
                        r="6"
                        class="fanout-fifo-dot-a fill-primary"
                        style="--dx-d1: 132px; --dy-d1: -79px"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-dot-b"
                        style="--dx-d2: 132px; --dy-d2: 0px"
                    />
                    <circle
                        cx="240"
                        cy="110"
                        r="6"
                        class="fanout-fifo-dot-c"
                        style="--dx-d3: 132px; --dy-d3: 79px"
                    />
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
                        class="fanout-fifo-flash-1 fill-primary"
                    />
                    <rect
                        x="80"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-2 fill-primary"
                    />
                    <rect
                        x="152"
                        y="170"
                        width="60"
                        height="30"
                        rx="6"
                        class="fanout-fifo-flash-3 fill-primary"
                    />
                    <circle
                        cx="110"
                        cy="38"
                        r="6"
                        class="fanout-fifo-dot-a fill-primary"
                        style="--dx-d1: -72px; --dy-d1: 92px"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-dot-b"
                        style="--dx-d2: 0px; --dy-d2: 92px"
                    />
                    <circle
                        cx="110"
                        cy="78"
                        r="6"
                        class="fanout-fifo-dot-c"
                        style="--dx-d3: 72px; --dy-d3: 92px"
                    />
                </g>
            </svg>

            <figcaption class="mt-3 text-sm text-muted-foreground">
                FIFO — destinations receive it strictly in order, one at a time.
            </figcaption>
        </figure>
    </div>
</template>

<style scoped>
/* Panel A — Async: one dot Ingest -> Proxy junction, then splits into three
   dots that travel simultaneously to their destinations. 2.4s loop. */
@keyframes fanout-async-main {
    0% {
        opacity: 1;
        transform: translate(0, 0);
    }
    35% {
        opacity: 1;
        transform: translate(var(--dx-j), var(--dy-j));
    }
    35.5%,
    100% {
        opacity: 0;
    }
}
.fanout-async-main {
    animation: fanout-async-main 2.4s linear infinite;
}

@keyframes fanout-async-branch {
    0%,
    34.9% {
        opacity: 0;
        transform: translate(0, 0);
    }
    35% {
        opacity: 1;
        transform: translate(0, 0);
    }
    85% {
        opacity: 1;
        transform: translate(var(--dx-d), var(--dy-d));
    }
    97% {
        opacity: 1;
        transform: translate(var(--dx-d), var(--dy-d));
    }
    100% {
        opacity: 0;
        transform: translate(var(--dx-d), var(--dy-d));
    }
}
.fanout-async-branch {
    animation: fanout-async-branch 2.4s linear infinite;
}

@keyframes fanout-flash-async {
    0%,
    84.9% {
        opacity: 0;
    }
    85% {
        opacity: 1;
    }
    90% {
        opacity: 1;
    }
    91.25%,
    100% {
        opacity: 0;
    }
}
.fanout-flash-async {
    opacity: 0;
    animation: fanout-flash-async 2.4s linear infinite;
}

/* Panel B — FIFO: dot A goes Ingest -> junction -> Destination 1. Dots B/C
   wait, muted, at the junction until their turn, then recolor and travel.
   4.2s loop. */
@keyframes fanout-fifo-dot-a {
    0% {
        opacity: 1;
        transform: translate(0, 0);
    }
    20% {
        opacity: 1;
        transform: translate(var(--dx-j), var(--dy-j));
    }
    45% {
        opacity: 1;
        transform: translate(
            calc(var(--dx-j) + var(--dx-d1)),
            calc(var(--dy-j) + var(--dy-d1))
        );
    }
    97% {
        opacity: 1;
    }
    100% {
        opacity: 0;
    }
}
.fanout-fifo-dot-a {
    animation: fanout-fifo-dot-a 4.2s linear infinite;
}

@keyframes fanout-fifo-dot-b {
    0%,
    19.9% {
        opacity: 0;
        transform: translate(0, 0);
        fill: var(--color-muted-foreground);
    }
    20% {
        opacity: 1;
        transform: translate(0, 0);
        fill: var(--color-muted-foreground);
    }
    44.9% {
        fill: var(--color-muted-foreground);
        transform: translate(0, 0);
    }
    45% {
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    70% {
        fill: var(--color-primary);
        opacity: 1;
        transform: translate(var(--dx-d2), var(--dy-d2));
    }
    97% {
        opacity: 1;
    }
    100% {
        opacity: 0;
    }
}
.fanout-fifo-dot-b {
    animation: fanout-fifo-dot-b 4.2s linear infinite;
}

@keyframes fanout-fifo-dot-c {
    0%,
    44.9% {
        opacity: 0;
        transform: translate(0, 0);
        fill: var(--color-muted-foreground);
    }
    45% {
        opacity: 1;
        transform: translate(0, 0);
        fill: var(--color-muted-foreground);
    }
    69.9% {
        fill: var(--color-muted-foreground);
        transform: translate(0, 0);
    }
    70% {
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    95% {
        fill: var(--color-primary);
        opacity: 1;
        transform: translate(var(--dx-d3), var(--dy-d3));
    }
    98% {
        opacity: 1;
    }
    100% {
        opacity: 0;
    }
}
.fanout-fifo-dot-c {
    animation: fanout-fifo-dot-c 4.2s linear infinite;
}

@keyframes fanout-fifo-flash-1 {
    0%,
    44.9% {
        opacity: 0;
    }
    45% {
        opacity: 1;
    }
    47% {
        opacity: 1;
    }
    48.6%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-flash-1 {
    opacity: 0;
    animation: fanout-fifo-flash-1 4.2s linear infinite;
}

@keyframes fanout-fifo-flash-2 {
    0%,
    69.9% {
        opacity: 0;
    }
    70% {
        opacity: 1;
    }
    73% {
        opacity: 1;
    }
    74.6%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-flash-2 {
    opacity: 0;
    animation: fanout-fifo-flash-2 4.2s linear infinite;
}

@keyframes fanout-fifo-flash-3 {
    0%,
    94.9% {
        opacity: 0;
    }
    95% {
        opacity: 1;
    }
    97% {
        opacity: 1;
    }
    98.6%,
    100% {
        opacity: 0;
    }
}
.fanout-fifo-flash-3 {
    opacity: 0;
    animation: fanout-fifo-flash-3 4.2s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
    .fanout-async-main,
    .fanout-async-branch,
    .fanout-flash-async,
    .fanout-fifo-dot-a,
    .fanout-fifo-dot-b,
    .fanout-fifo-dot-c,
    .fanout-fifo-flash-1,
    .fanout-fifo-flash-2,
    .fanout-fifo-flash-3 {
        animation: none;
    }
}
</style>
