<script setup lang="ts">
import {
    applyTracking,
    buildPathTable,
    compositeGrid,
    DIAGRAM_FONT,
    drawHeatBand,
    drawNodeEdge,
    drawNodeLabel,
    drawPulse,
    drawRoundedRect,
    ease,
    labelSizeFor,
    withAlpha,
} from './canvasKit';
import type { BaseVisuals } from './canvasKit';
import type { PathSpec, PathTable, Point } from './canvasKit';
import type { CanvasBase } from './useCanvasIllustration';
import { useCanvasIllustration } from './useCanvasIllustration';

// ---------------------------------------------------------------------------
// What this draws
//
// One delivery to one destination, failing and being retried on a widening
// backoff until it is terminally failed, then replayed by hand and delivered.
// It shares the fan-out diagram's vocabulary deliberately: a charge pulse in
// transit, a node border that lights on arrival, a drifting grid field. Where
// it departs is colour — a failed attempt lands in `--destructive`, so failure
// is legible without any text explaining it.
// ---------------------------------------------------------------------------

type Phase =
    'attempt' | 'failed' | 'waiting' | 'terminal' | 'replay' | 'delivered';

interface Step {
    phase: Phase;
    start: number;
    end: number;
    /** Which attempt this belongs to, for the label. */
    attempt: number;
}

const TRAVEL_MS = 1100;
const IMPACT_MS = 900;
const TERMINAL_MS = 2200;
const DELIVERED_MS = 2600;
const GAP_MS = 260;

// Each wait is visibly longer than the last — that widening gap is the backoff
// curve, and it is the one thing this diagram most needs to communicate.
const BACKOFF_MS = [1400, 2400];

function buildTimeline(): Step[] {
    const steps: Step[] = [];
    let t = 600;

    const push = (phase: Phase, duration: number, attempt: number) => {
        steps.push({ phase, start: t, end: t + duration, attempt });
        t += duration;
    };

    for (let attempt = 1; attempt <= 3; attempt++) {
        push('attempt', TRAVEL_MS, attempt);
        push('failed', IMPACT_MS, attempt);

        if (attempt < 3) {
            push('waiting', BACKOFF_MS[attempt - 1], attempt);
        }
    }

    push('terminal', TERMINAL_MS, 3);
    push('replay', TRAVEL_MS, 4);
    push('delivered', DELIVERED_MS, 4);
    t += GAP_MS;

    return steps;
}

const TIMELINE = buildTimeline();
const LOOP_MS = TIMELINE[TIMELINE.length - 1].end + GAP_MS;

const PHASE_LABEL: Record<Phase, string> = {
    attempt: 'DELIVERY ATTEMPT',
    failed: 'FAILED',
    waiting: 'BACKOFF',
    terminal: 'TERMINALLY FAILED',
    replay: 'REPLAYED',
    delivered: 'DELIVERED',
};

interface Geometry {
    width: number;
    height: number;
    scale: number;
    compact: boolean;
    source: Point;
    target: Point;
    nodeW: number;
    nodeH: number;
    cornerR: number;
    path: PathSpec;
    table: PathTable;
}

function computeGeometry(width: number, height: number): Geometry {
    const scale = Math.min(1, Math.max(0.55, width / 560));
    const compact = width < 520;
    const nodeW = width * (compact ? 0.34 : 0.16);
    const nodeH = height * (compact ? 0.16 : 0.2);
    const source = { x: width * (compact ? 0.22 : 0.14), y: height * 0.5 };
    const target = { x: width * (compact ? 0.78 : 0.86), y: height * 0.5 };
    const path: PathSpec = {
        kind: 'line',
        p0: { x: source.x + nodeW / 2, y: source.y },
        p1: { x: target.x - nodeW / 2, y: target.y },
    };

    return {
        width,
        height,
        scale,
        compact,
        source,
        target,
        nodeW,
        nodeH,
        cornerR: Math.min(nodeW, nodeH) * 0.2,
        path,
        table: buildPathTable(path),
    };
}

// Everything this diagram needs beyond the shared base. It adds nothing today —
// the retry sequence draws the same marks as the fan-out, only in a different
// order and with failure in --destructive.
type ThemeVisuals = BaseVisuals;

const DARK_VISUALS: ThemeVisuals = {
    gridLineAlpha: 0.16,
    idleLineAlpha: 0.22,
    idleLineWidth: 1.5,
    pulseCoreWidth: 5.5,
    pulseTailLength: 82,
    bloomBlur: 20,
    bloomAlpha: 0.55,
    edgeWidth: 2,
    edgeAlpha: 0.95,
    edgeBlur: 18,
    nodeStrokeAlpha: 0.45,
    labelAlpha: 0.9,
};

const LIGHT_VISUALS: ThemeVisuals = {
    gridLineAlpha: 0.3,
    idleLineAlpha: 0.3,
    idleLineWidth: 1.5,
    pulseCoreWidth: 5,
    pulseTailLength: 78,
    bloomBlur: 8,
    bloomAlpha: 0.26,
    edgeWidth: 2,
    edgeAlpha: 0.9,
    edgeBlur: 8,
    nodeStrokeAlpha: 0.9,
    labelAlpha: 1,
};

interface RenderState extends CanvasBase<ThemeVisuals> {
    geo: Geometry;
}

function activeStep(localT: number): Step {
    for (const step of TIMELINE) {
        if (localT >= step.start && localT < step.end) {
            return step;
        }
    }

    return TIMELINE[TIMELINE.length - 1];
}

// The phase caption, top-left in the same treatment the fan-out diagram uses
// for its mode legend. It carries the attempt count, which is the one thing the
// motion alone cannot say.
function drawCaption(state: RenderState, step: Step): void {
    const { ctx, geo, tokens, isDark } = state;
    const fontPx = Math.round((geo.compact ? 11 : 13) * geo.scale);
    const failing = step.phase === 'failed' || step.phase === 'terminal';
    const succeeded = step.phase === 'delivered';

    let text = PHASE_LABEL[step.phase];

    if (step.phase === 'attempt' || step.phase === 'failed') {
        text = `${text} ${step.attempt}/3`;
    }

    ctx.save();
    ctx.font = `600 ${fontPx}px ${DIAGRAM_FONT}`;
    ctx.textAlign = geo.compact ? 'center' : 'left';
    ctx.textBaseline = 'top';
    applyTracking(ctx, fontPx * 0.18);
    ctx.fillStyle = failing
        ? withAlpha(tokens.destructive, isDark ? 0.95 : 1)
        : succeeded
          ? withAlpha(tokens.accentFrom, 1)
          : withAlpha(tokens.mutedForeground, 0.65);
    ctx.fillText(
        text,
        geo.compact ? geo.width * 0.5 : geo.width * 0.035,
        geo.compact ? geo.height * 0.02 : geo.height * 0.08,
    );
    ctx.restore();
}

// Thin wrappers over the shared primitives — they bind this diagram's geometry
// and theme table so the scene code below reads as a sequence of beats rather
// than as argument plumbing.
function pulse(state: RenderState, head: number): void {
    const { geo, visuals, tokens } = state;
    drawPulse(state.ctx, {
        table: geo.table,
        headT: head,
        headColor: tokens.accentTo,
        tailColor: tokens.accentFrom,
        coreWidth: visuals.pulseCoreWidth * geo.scale,
        tailLength: visuals.pulseTailLength * geo.scale,
        bloomBlur: visuals.bloomBlur * geo.scale,
        bloomAlpha: visuals.bloomAlpha,
        isDark: state.isDark,
    });
}

function heat(state: RenderState, head: number, color: string): void {
    const { geo, visuals } = state;
    drawHeatBand(state.ctx, {
        spec: geo.path,
        head,
        peakAlpha: 0.45,
        width: visuals.idleLineWidth * 1.6 * geo.scale,
        color,
        isDark: state.isDark,
    });
}

function edge(
    state: RenderState,
    center: Point,
    color: string,
    intensity: number,
): void {
    if (intensity <= 0) {
        return;
    }

    const { geo, visuals } = state;
    drawNodeEdge(state.ctx, {
        center,
        width: geo.nodeW,
        height: geo.nodeH,
        radius: geo.cornerR,
        stroke: withAlpha(color, visuals.edgeAlpha * intensity),
        lineWidth: visuals.edgeWidth * geo.scale,
        blur: visuals.edgeBlur * geo.scale * intensity,
        shadowColor: withAlpha(color, visuals.bloomAlpha * intensity),
        isDark: state.isDark,
    });
}

function label(state: RenderState, center: Point, text: string): void {
    const { geo, tokens, visuals } = state;
    const fontPx = labelSizeFor(text, geo.nodeW, 11 * geo.scale);
    drawNodeLabel(
        state.ctx,
        center,
        text,
        withAlpha(tokens.mutedForeground, visuals.labelAlpha),
        fontPx,
        fontPx * (geo.compact ? 0.04 : 0.12),
    );
}

function drawScene(state: RenderState, localT: number, timeMs: number): void {
    const { ctx, geo, tokens, visuals } = state;
    const step = activeStep(localT);
    const elapsed = localT - step.start;
    const duration = step.end - step.start;
    const progress = Math.min(1, Math.max(0, elapsed / duration));

    ctx.clearRect(0, 0, state.width, state.height);
    compositeGrid(
        ctx,
        state.gridLayer,
        state.scratch,
        state.dpr,
        timeMs,
        state.reducedMotion,
    );

    // Idle wire
    const { p0, p1 } = geo.path as { p0: Point; p1: Point };
    ctx.beginPath();
    ctx.moveTo(p0.x, p0.y);
    ctx.lineTo(p1.x, p1.y);
    ctx.lineCap = 'round';
    ctx.lineWidth = visuals.idleLineWidth * geo.scale;
    ctx.strokeStyle = withAlpha(tokens.mutedForeground, visuals.idleLineAlpha);
    ctx.stroke();

    const nodeStroke = withAlpha(
        tokens.mutedForeground,
        visuals.nodeStrokeAlpha,
    );
    const strokeWidth = 1 * geo.scale;

    for (const node of [geo.source, geo.target]) {
        drawRoundedRect(
            ctx,
            node,
            geo.nodeW,
            geo.nodeH,
            geo.cornerR,
            tokens.card,
            nodeStroke,
            strokeWidth,
        );
    }

    label(state, geo.source, 'DELIVERY');
    label(state, geo.target, 'DESTINATION');

    const isReplay = step.phase === 'replay';

    if (step.phase === 'attempt' || isReplay) {
        const head = ease('inout', progress);
        // A replay is charged like any other delivery; only its outcome differs,
        // so it travels in the same colours rather than announcing itself.
        heat(state, head, tokens.accentFrom);
        pulse(state, head);
        edge(
            state,
            geo.source,
            tokens.accentFrom,
            Math.max(0, 1 - progress * 3),
        );
    }

    if (step.phase === 'failed') {
        // Failure lands as a hard rise and a slow release, the inverse of a
        // delivery's soft arrival — it should feel like an impact.
        const intensity =
            progress < 0.12
                ? progress / 0.12
                : 1 - ease('out', (progress - 0.12) / 0.88);
        edge(state, geo.target, tokens.destructive, intensity);
    }

    if (step.phase === 'waiting') {
        // The backoff itself: a dim charge that creeps a little way down the
        // wire and stalls, restarting as the wait stretches. The stall is the
        // point — nothing is being delivered while this plays.
        const creep = ease('out', Math.min(1, progress * 1.6)) * 0.22;
        heat(state, creep, tokens.mutedForeground);
    }

    if (step.phase === 'terminal') {
        // Held, not decaying: a terminal failure is a state the delivery stays
        // in until someone acts on it.
        const intensity = progress < 0.1 ? progress / 0.1 : 1;
        edge(state, geo.target, tokens.destructive, intensity);
    }

    if (step.phase === 'delivered') {
        const intensity =
            progress < 0.08 ? progress / 0.08 : 1 - progress * 0.5;
        edge(state, geo.target, tokens.accentFrom, intensity);
    }

    drawCaption(state, step);
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

// The composable binds the template refs by name (`ref="container"` /
// `ref="canvas"`), so nothing needs to come back out of it here.
useCanvasIllustration<ThemeVisuals, RenderState>({
    visualsFor: (isDark) => (isDark ? DARK_VISUALS : LIGHT_VISUALS),
    gridAlpha: (visuals) => visuals.gridLineAlpha,
    extend: (base) => ({
        ...base,
        geo: computeGeometry(base.width, base.height),
    }),
    drawFrame: (state, elapsed) => drawScene(state, elapsed % LOOP_MS, elapsed),
    // The terminal moment: the single frame that says the most without motion.
    drawStatic: (state) =>
        drawScene(
            state,
            TIMELINE.find((step) => step.phase === 'terminal')!.start + 1,
            0,
        ),
});
</script>

<template>
    <div class="flex flex-col items-center">
        <p class="sr-only">
            A delivery is attempted, fails, and is retried after a wait that
            grows each time. Once retries are exhausted it is marked terminally
            failed, and can then be replayed by hand and delivered.
        </p>

        <div
            ref="container"
            class="mx-auto aspect-square w-full max-w-4xl overflow-hidden rounded-2xl border border-border sm:aspect-[5/2]"
        >
            <canvas
                ref="canvas"
                aria-hidden="true"
                class="block h-full w-full"
            ></canvas>
        </div>
    </div>
</template>
