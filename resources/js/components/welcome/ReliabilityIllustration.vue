<script setup lang="ts">
import { onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import {
    applyTracking,
    buildGridLayer,
    buildPathTable,
    compositeGrid,
    DIAGRAM_FONT,
    drawRoundedRect,
    ease,
    glowBlend,
    lengthAtT,
    pointAtLength,
    readTokens,
    withAlpha,
} from './canvasKit';
import type { PathSpec, PathTable, Point, Tokens } from './canvasKit';

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

interface ThemeVisuals {
    gridLineAlpha: number;
    idleLineAlpha: number;
    idleLineWidth: number;
    pulseCoreWidth: number;
    pulseTailLength: number;
    bloomBlur: number;
    bloomAlpha: number;
    edgeWidth: number;
    edgeAlpha: number;
    edgeBlur: number;
    nodeStrokeAlpha: number;
    labelAlpha: number;
}

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

interface RenderState {
    ctx: CanvasRenderingContext2D;
    width: number;
    height: number;
    dpr: number;
    geo: Geometry;
    tokens: Tokens;
    isDark: boolean;
    visuals: ThemeVisuals;
    gridLayer: HTMLCanvasElement;
    scratch: HTMLCanvasElement;
    reducedMotion: boolean;
}

function activeStep(localT: number): Step {
    for (const step of TIMELINE) {
        if (localT >= step.start && localT < step.end) {
            return step;
        }
    }

    return TIMELINE[TIMELINE.length - 1];
}

function drawNodeLabel(state: RenderState, center: Point, text: string): void {
    const { ctx, geo, tokens, visuals } = state;
    const nominal = 11 * geo.scale;
    const maxByWidth = (geo.nodeW * 0.82) / (text.length * 0.72);
    const fontPx = Math.max(7, Math.round(Math.min(nominal, maxByWidth)));

    ctx.save();
    ctx.font = `500 ${fontPx}px ${DIAGRAM_FONT}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = withAlpha(tokens.mutedForeground, visuals.labelAlpha);
    applyTracking(ctx, fontPx * (geo.compact ? 0.04 : 0.12));
    ctx.fillText(text, center.x, center.y);
    ctx.restore();
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

function drawPulse(
    state: RenderState,
    headT: number,
    headColor: string,
    tailColor: string,
): void {
    const { ctx, geo, visuals, isDark } = state;
    const lineWidth = visuals.pulseCoreWidth * geo.scale;
    const tailLength = visuals.pulseTailLength * geo.scale;
    const headLen = lengthAtT(geo.table, headT);
    const headPoint = pointAtLength(geo.table, headLen);
    const tailPoint = pointAtLength(geo.table, headLen - tailLength);

    const gradient = ctx.createLinearGradient(
        headPoint.x,
        headPoint.y,
        tailPoint.x,
        tailPoint.y,
    );
    const stops = 16;

    for (let i = 0; i <= stops; i++) {
        const frac = i / stops;
        const alpha = 1 - ease('out', frac);
        gradient.addColorStop(
            frac,
            withAlpha(frac < 0.55 ? headColor : tailColor, alpha),
        );
    }

    const samples = 20;
    ctx.save();
    ctx.globalCompositeOperation = glowBlend(isDark);
    ctx.beginPath();
    ctx.moveTo(headPoint.x, headPoint.y);

    for (let i = 1; i <= samples; i++) {
        const point = pointAtLength(
            geo.table,
            headLen - (i / samples) * tailLength,
        );
        ctx.lineTo(point.x, point.y);
    }

    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = lineWidth;
    ctx.strokeStyle = gradient;
    ctx.shadowBlur = visuals.bloomBlur * geo.scale;
    ctx.shadowColor = withAlpha(headColor, visuals.bloomAlpha);
    ctx.stroke();
    ctx.restore();
}

// A travelling heat band, transparent outside the lit zone so the base wire
// shows through — the same treatment the fan-out diagram uses.
function drawHeatBand(state: RenderState, head: number, color: string): void {
    const { ctx, geo, visuals, isDark } = state;
    const { p0, p1 } = geo.path as { p0: Point; p1: Point };
    const gradient = ctx.createLinearGradient(p0.x, p0.y, p1.x, p1.y);
    const TRAIL = 0.34;
    const trailStop = Math.min(0.998, Math.max(0, head - TRAIL));
    const headStop = Math.min(0.999, Math.max(trailStop + 0.001, head));
    const leadStop = Math.min(1, headStop + 0.05);

    gradient.addColorStop(0, withAlpha(color, 0));
    gradient.addColorStop(trailStop, withAlpha(color, 0));
    gradient.addColorStop(
        trailStop + (headStop - trailStop) * 0.55,
        withAlpha(color, 0.2),
    );
    gradient.addColorStop(headStop, withAlpha(color, 0.45));

    if (leadStop < 1) {
        gradient.addColorStop(leadStop, withAlpha(color, 0));
        gradient.addColorStop(1, withAlpha(color, 0));
    }

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(isDark);
    ctx.beginPath();
    ctx.moveTo(p0.x, p0.y);
    ctx.lineTo(p1.x, p1.y);
    ctx.lineCap = 'round';
    ctx.lineWidth = visuals.idleLineWidth * 1.6 * geo.scale;
    ctx.strokeStyle = gradient;
    ctx.stroke();
    ctx.restore();
}

function drawNodeEdge(
    state: RenderState,
    center: Point,
    color: string,
    intensity: number,
): void {
    const { ctx, geo, visuals, isDark } = state;

    if (intensity <= 0) {
        return;
    }

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(isDark);
    ctx.beginPath();
    ctx.roundRect(
        center.x - geo.nodeW / 2,
        center.y - geo.nodeH / 2,
        geo.nodeW,
        geo.nodeH,
        geo.cornerR,
    );
    ctx.lineWidth = visuals.edgeWidth * geo.scale;
    ctx.strokeStyle = withAlpha(color, visuals.edgeAlpha * intensity);
    ctx.shadowBlur = visuals.edgeBlur * geo.scale * intensity;
    ctx.shadowColor = withAlpha(color, visuals.bloomAlpha * intensity);
    ctx.stroke();
    ctx.restore();
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

    drawNodeLabel(state, geo.source, 'DELIVERY');
    drawNodeLabel(state, geo.target, 'DESTINATION');

    const isReplay = step.phase === 'replay';

    if (step.phase === 'attempt' || isReplay) {
        const head = ease('inout', progress);
        // A replay is charged like any other delivery; only its outcome differs,
        // so it travels in the same colours rather than announcing itself.
        drawHeatBand(state, head, tokens.accentFrom);
        drawPulse(state, head, tokens.accentTo, tokens.accentFrom);
        drawNodeEdge(
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
        drawNodeEdge(state, geo.target, tokens.destructive, intensity);
    }

    if (step.phase === 'waiting') {
        // The backoff itself: a dim charge that creeps a little way down the
        // wire and stalls, restarting as the wait stretches. The stall is the
        // point — nothing is being delivered while this plays.
        const creep = ease('out', Math.min(1, progress * 1.6)) * 0.22;
        drawHeatBand(state, creep, tokens.mutedForeground);
    }

    if (step.phase === 'terminal') {
        // Held, not decaying: a terminal failure is a state the delivery stays
        // in until someone acts on it.
        const intensity = progress < 0.1 ? progress / 0.1 : 1;
        drawNodeEdge(state, geo.target, tokens.destructive, intensity);
    }

    if (step.phase === 'delivered') {
        const intensity =
            progress < 0.08 ? progress / 0.08 : 1 - progress * 0.5;
        drawNodeEdge(state, geo.target, tokens.accentFrom, intensity);
    }

    drawCaption(state, step);
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const containerRef = useTemplateRef<HTMLDivElement>('container');
const canvasRef = useTemplateRef<HTMLCanvasElement>('canvas');
const prefersReducedMotion = ref(false);

let state: RenderState | null = null;
let rafId: number | null = null;
let startTime = 0;
let resizeObserver: ResizeObserver | null = null;
let mutationObserver: MutationObserver | null = null;
let motionQuery: MediaQueryList | null = null;

function buildRenderState(
    canvas: HTMLCanvasElement,
    container: HTMLDivElement,
): RenderState | null {
    const ctx = canvas.getContext('2d');
    const rect = container.getBoundingClientRect();

    if (!ctx || rect.width === 0 || rect.height === 0) {
        return null;
    }

    const dpr = window.devicePixelRatio || 1;
    const width = rect.width;
    const height = rect.height;

    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    const tokens = readTokens();
    const isDark = document.documentElement.classList.contains('dark');
    const visuals = isDark ? DARK_VISUALS : LIGHT_VISUALS;
    const scratch = document.createElement('canvas');
    scratch.width = Math.round(width * dpr);
    scratch.height = Math.round(height * dpr);

    return {
        ctx,
        width,
        height,
        dpr,
        geo: computeGeometry(width, height),
        tokens,
        isDark,
        visuals,
        gridLayer: buildGridLayer(
            width,
            height,
            dpr,
            tokens.mutedForeground,
            visuals.gridLineAlpha,
        ),
        scratch,
        reducedMotion: prefersReducedMotion.value,
    };
}

function tick(now: number) {
    if (!state) {
        return;
    }

    drawScene(state, (now - startTime) % LOOP_MS, now - startTime);
    rafId = requestAnimationFrame(tick);
}

function drawStaticFrame() {
    if (!state) {
        return;
    }

    // The terminal moment: the one frame that says the most without motion.
    drawScene(
        state,
        TIMELINE.find((s) => s.phase === 'terminal')!.start + 1,
        0,
    );
}

function startOrRestart() {
    if (rafId !== null) {
        cancelAnimationFrame(rafId);
        rafId = null;
    }

    if (!state) {
        return;
    }

    if (prefersReducedMotion.value) {
        drawStaticFrame();

        return;
    }

    startTime = performance.now();
    rafId = requestAnimationFrame(tick);
}

function rebuild() {
    const canvas = canvasRef.value;
    const container = containerRef.value;

    if (!canvas || !container) {
        return;
    }

    state = buildRenderState(canvas, container);
    startOrRestart();
}

onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    prefersReducedMotion.value = motionQuery.matches;
    motionQuery.addEventListener('change', () => {
        prefersReducedMotion.value = motionQuery?.matches ?? false;
        rebuild();
    });

    rebuild();

    if (containerRef.value) {
        resizeObserver = new ResizeObserver(() => rebuild());
        resizeObserver.observe(containerRef.value);
    }

    mutationObserver = new MutationObserver(() => rebuild());
    mutationObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
});

onUnmounted(() => {
    if (rafId !== null) {
        cancelAnimationFrame(rafId);
    }

    resizeObserver?.disconnect();
    mutationObserver?.disconnect();
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
            class="mx-auto aspect-square w-full max-w-4xl sm:aspect-[5/2]"
        >
            <canvas
                ref="canvas"
                aria-hidden="true"
                class="block h-full w-full"
            ></canvas>
        </div>
    </div>
</template>
