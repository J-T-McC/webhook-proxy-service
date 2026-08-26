<script setup lang="ts">
// Illustration 2 (design-landing-page.md): one delivery's lifecycle — bounded
// backoff retry, terminal failure, manual replay. Single 10s loop; the
// keyframe percentages below follow the spec's timeline table directly.
// Decorative only — the Section D 4-step list carries the same facts as
// always-visible prose.

const staticSteps = [
    {
        badge: '1',
        label: 'Attempt 1 (failed)',
        classes: 'border-destructive text-destructive',
    },
    {
        badge: '2',
        label: 'Attempt 2 (failed)',
        classes: 'border-destructive text-destructive',
    },
    {
        badge: '!',
        label: 'Terminally failed',
        classes: 'border-destructive border-dashed text-destructive',
    },
    { badge: '↻', label: 'Replay', classes: 'border-border text-foreground' },
    { badge: '✓', label: 'Delivered', classes: 'border-primary text-primary' },
];
</script>

<template>
    <div>
        <!-- Motion-safe: horizontal layout (sm and up) -->
        <svg
            viewBox="0 0 460 170"
            preserveAspectRatio="xMidYMid meet"
            aria-hidden="true"
            class="hidden w-full motion-reduce:hidden sm:motion-safe:block"
            style="--dx-dest: 282px; --dy-dest: 0px"
        >
            <line
                x1="88"
                y1="80"
                x2="372"
                y2="80"
                class="stroke-border"
                stroke-width="2"
                stroke-linecap="round"
            />
            <rect
                x="12"
                y="65"
                width="76"
                height="30"
                rx="6"
                class="fill-card stroke-border"
                stroke-width="2"
            />
            <text
                x="50"
                y="84"
                text-anchor="middle"
                class="fill-foreground text-[10px]"
            >
                Origin
            </text>

            <rect
                x="372"
                y="65"
                width="76"
                height="30"
                rx="6"
                class="reliability-dest-border fill-card"
                stroke-width="2"
            />
            <rect
                x="372"
                y="65"
                width="76"
                height="30"
                rx="6"
                class="reliability-dest-flash fill-primary"
            />
            <text
                x="410"
                y="84"
                text-anchor="middle"
                class="fill-foreground text-[10px]"
            >
                Destination
            </text>

            <rect
                x="180"
                y="100"
                width="100"
                height="8"
                rx="4"
                class="reliability-wait-bg fill-muted"
            />
            <rect
                x="180"
                y="100"
                width="100"
                height="8"
                rx="4"
                class="reliability-wait-fill fill-muted-foreground"
                style="transform-origin: 180px center"
            />
            <text
                x="230"
                y="124"
                text-anchor="middle"
                class="reliability-label-retrying fill-muted-foreground text-[9px]"
            >
                retrying…
            </text>
            <text
                x="230"
                y="124"
                text-anchor="middle"
                class="reliability-label-terminal fill-destructive text-[9px]"
            >
                Terminally failed — retries exhausted
            </text>
            <text
                x="230"
                y="124"
                text-anchor="middle"
                class="reliability-label-delivered fill-primary text-[9px]"
            >
                Delivered
            </text>

            <path
                d="M40 58 A 18 18 0 1 1 39 40"
                class="reliability-replay-icon fill-none stroke-primary"
                stroke-width="2"
                stroke-linecap="round"
            />
            <path
                d="M33 34 L 39 40 L 45 33"
                class="reliability-replay-icon fill-none stroke-primary"
                stroke-width="2"
                stroke-linecap="round"
            />

            <circle cx="90" cy="80" r="6" class="reliability-dot" />
        </svg>

        <!-- Motion-safe: vertical layout (below sm) -->
        <svg
            viewBox="0 0 220 380"
            preserveAspectRatio="xMidYMid meet"
            aria-hidden="true"
            class="w-full motion-reduce:hidden max-sm:motion-safe:block sm:hidden"
            style="--dx-dest: 0px; --dy-dest: 250px"
        >
            <line
                x1="110"
                y1="48"
                x2="110"
                y2="292"
                class="stroke-border"
                stroke-width="2"
                stroke-linecap="round"
            />
            <rect
                x="72"
                y="18"
                width="76"
                height="30"
                rx="6"
                class="fill-card stroke-border"
                stroke-width="2"
            />
            <text
                x="110"
                y="37"
                text-anchor="middle"
                class="fill-foreground text-[10px]"
            >
                Origin
            </text>

            <rect
                x="72"
                y="292"
                width="76"
                height="30"
                rx="6"
                class="reliability-dest-border fill-card"
                stroke-width="2"
            />
            <rect
                x="72"
                y="292"
                width="76"
                height="30"
                rx="6"
                class="reliability-dest-flash fill-primary"
            />
            <text
                x="110"
                y="311"
                text-anchor="middle"
                class="fill-foreground text-[10px]"
            >
                Destination
            </text>

            <rect
                x="60"
                y="160"
                width="8"
                height="90"
                rx="4"
                class="reliability-wait-bg fill-muted"
            />
            <rect
                x="60"
                y="160"
                width="8"
                height="90"
                rx="4"
                class="reliability-wait-fill-v fill-muted-foreground"
                style="transform-origin: center 160px"
            />
            <text
                x="110"
                y="207"
                text-anchor="middle"
                class="reliability-label-retrying fill-muted-foreground text-[9px]"
            >
                retrying…
            </text>
            <text
                x="110"
                y="207"
                text-anchor="middle"
                class="reliability-label-terminal fill-destructive text-[8px]"
            >
                Terminally failed
            </text>
            <text
                x="110"
                y="207"
                text-anchor="middle"
                class="reliability-label-delivered fill-primary text-[9px]"
            >
                Delivered
            </text>

            <path
                d="M95 12 A 14 14 0 1 1 94 -2"
                class="reliability-replay-icon fill-none stroke-primary"
                stroke-width="2"
                stroke-linecap="round"
                transform="translate(0, 12)"
            />

            <circle cx="110" cy="50" r="6" class="reliability-dot" />
        </svg>

        <!-- Reduced-motion: static stepper, replaces the animated diagram entirely -->
        <div
            class="hidden motion-reduce:grid motion-reduce:grid-cols-1 motion-reduce:gap-4 sm:motion-reduce:grid-cols-5 sm:motion-reduce:gap-2"
        >
            <div
                v-for="step in staticSteps"
                :key="step.label"
                class="flex items-center gap-3 sm:flex-col sm:text-center"
            >
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 text-xs font-medium"
                    :class="step.classes"
                >
                    {{ step.badge }}
                </span>
                <span class="text-xs text-muted-foreground">{{
                    step.label
                }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes reliability-dot {
    0% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    9% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(
            calc(var(--dx-dest) * 0.9),
            calc(var(--dy-dest) * 0.9)
        );
    }
    10%,
    11% {
        opacity: 1;
        fill: var(--color-destructive);
        transform: translate(var(--dx-dest), var(--dy-dest));
    }
    13%,
    19.9% {
        opacity: 0;
    }
    20% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    29% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(
            calc(var(--dx-dest) * 0.9),
            calc(var(--dy-dest) * 0.9)
        );
    }
    30%,
    31% {
        opacity: 1;
        fill: var(--color-destructive);
        transform: translate(var(--dx-dest), var(--dy-dest));
    }
    33%,
    49.9% {
        opacity: 0;
    }
    50% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    59% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(
            calc(var(--dx-dest) * 0.9),
            calc(var(--dy-dest) * 0.9)
        );
    }
    60%,
    61% {
        opacity: 1;
        fill: var(--color-destructive);
        transform: translate(var(--dx-dest), var(--dy-dest));
    }
    63%,
    82.9% {
        opacity: 0;
    }
    83% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(0, 0);
    }
    93%,
    100% {
        opacity: 1;
        fill: var(--color-primary);
        transform: translate(var(--dx-dest), var(--dy-dest));
    }
}
.reliability-dot {
    animation: reliability-dot 10s linear infinite;
}

@keyframes reliability-dest-border {
    0%,
    9.9% {
        stroke: var(--color-border);
        stroke-dasharray: 0;
    }
    10%,
    12% {
        stroke: var(--color-destructive);
        stroke-dasharray: 0;
    }
    13%,
    29.9% {
        stroke: var(--color-border);
        stroke-dasharray: 0;
    }
    30%,
    32% {
        stroke: var(--color-destructive);
        stroke-dasharray: 0;
    }
    33%,
    59.9% {
        stroke: var(--color-border);
        stroke-dasharray: 0;
    }
    60%,
    92.9% {
        stroke: var(--color-destructive);
        stroke-dasharray: 4 3;
    }
    93%,
    100% {
        stroke: var(--color-border);
        stroke-dasharray: 0;
    }
}
.reliability-dest-border {
    animation: reliability-dest-border 10s linear infinite;
}

@keyframes reliability-dest-flash {
    0%,
    92.9% {
        opacity: 0;
    }
    93%,
    95% {
        opacity: 1;
    }
    97%,
    100% {
        opacity: 0;
    }
}
.reliability-dest-flash {
    opacity: 0;
    animation: reliability-dest-flash 10s linear infinite;
}

@keyframes reliability-wait-visibility {
    0%,
    9.9% {
        opacity: 0;
    }
    10%,
    20% {
        opacity: 1;
    }
    20.1%,
    29.9% {
        opacity: 0;
    }
    30%,
    50% {
        opacity: 1;
    }
    50.1%,
    100% {
        opacity: 0;
    }
}
.reliability-wait-bg {
    animation: reliability-wait-visibility 10s linear infinite;
}

@keyframes reliability-wait-fill {
    0%,
    9.9% {
        opacity: 0;
        transform: scaleX(0);
    }
    10% {
        opacity: 1;
        transform: scaleX(0);
    }
    20% {
        opacity: 1;
        transform: scaleX(1);
    }
    20.1%,
    29.9% {
        opacity: 0;
        transform: scaleX(0);
    }
    30% {
        opacity: 1;
        transform: scaleX(0);
    }
    50% {
        opacity: 1;
        transform: scaleX(1);
    }
    50.1%,
    100% {
        opacity: 0;
        transform: scaleX(0);
    }
}
.reliability-wait-fill {
    animation: reliability-wait-fill 10s linear infinite;
}

@keyframes reliability-wait-fill-v {
    0%,
    9.9% {
        opacity: 0;
        transform: scaleY(0);
    }
    10% {
        opacity: 1;
        transform: scaleY(0);
    }
    20% {
        opacity: 1;
        transform: scaleY(1);
    }
    20.1%,
    29.9% {
        opacity: 0;
        transform: scaleY(0);
    }
    30% {
        opacity: 1;
        transform: scaleY(0);
    }
    50% {
        opacity: 1;
        transform: scaleY(1);
    }
    50.1%,
    100% {
        opacity: 0;
        transform: scaleY(0);
    }
}
.reliability-wait-fill-v {
    animation: reliability-wait-fill-v 10s linear infinite;
}

@keyframes reliability-label-retrying {
    0%,
    10.9% {
        opacity: 0;
    }
    11%,
    19% {
        opacity: 1;
    }
    20%,
    30.9% {
        opacity: 0;
    }
    31%,
    49% {
        opacity: 1;
    }
    50%,
    100% {
        opacity: 0;
    }
}
.reliability-label-retrying {
    opacity: 0;
    animation: reliability-label-retrying 10s linear infinite;
}

@keyframes reliability-label-terminal {
    0%,
    62.9% {
        opacity: 0;
    }
    63%,
    92.9% {
        opacity: 1;
    }
    93%,
    100% {
        opacity: 0;
    }
}
.reliability-label-terminal {
    opacity: 0;
    animation: reliability-label-terminal 10s linear infinite;
}

@keyframes reliability-label-delivered {
    0%,
    93.9% {
        opacity: 0;
    }
    94%,
    100% {
        opacity: 1;
    }
}
.reliability-label-delivered {
    opacity: 0;
    animation: reliability-label-delivered 10s linear infinite;
}

@keyframes reliability-replay-icon {
    0%,
    79.9% {
        opacity: 0;
    }
    81%,
    92.9% {
        opacity: 1;
    }
    93%,
    100% {
        opacity: 0;
    }
}
.reliability-replay-icon {
    opacity: 0;
    animation: reliability-replay-icon 10s linear infinite;
}
</style>
