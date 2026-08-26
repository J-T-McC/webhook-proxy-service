<script setup lang="ts">
// Illustration 1 (design-landing-page.md, Redesign Notes 2026-08-25): one
// canvas-drawn diagram — two inbound event nodes on the left, each with its
// own junction, fanning out to the same three shared destination nodes.
// Async then FIFO play back to back forever, driven by a small
// requestAnimationFrame loop over two declarative timeline-schema instances
// (below), rather than hand-authored per-element keyframes. The travelling
// element is a "charge pulse" (bright core + bloom + eased falloff tail)
// current-like along paths that sit dim at rest, brighten under the pulse,
// and decay back — not a ball sliding along a line. Ordering/exclusivity is
// EVENT-level, not per-destination (ADR-011 §38/§130,
// AdvanceProxyFifoQueue's docblock) — FIFO admits at most one event in
// flight per proxy at a time; Async admits both concurrently. Every fact a
// viewer needs is carried by the DOM legend below, never by canvas text.
import { onMounted, onUnmounted, ref, useTemplateRef } from 'vue';

// ---------------------------------------------------------------------------
// Timeline schema (design spec "Timeline schema" section)
// ---------------------------------------------------------------------------

type SegmentKey =
    'ingest-junction' | 'junction-dest1' | 'junction-dest2' | 'junction-dest3';

type EasingName = 'inout' | 'out' | 'decay';

interface TravelEntry {
    event: 1 | 2;
    kind: 'travel';
    segment: SegmentKey;
    start: number;
    end: number;
    easing: EasingName;
}

interface ArrivalRingEntry {
    event: 1 | 2;
    kind: 'arrivalRing';
    dest: 1 | 2 | 3;
    start: number;
    end: number;
    easing: EasingName;
}

interface QueuedEntry {
    event: 1 | 2;
    kind: 'queued';
    start: number;
    end: number;
}

type TimelineEntry = TravelEntry | ArrivalRingEntry | QueuedEntry;

interface Schema {
    id: 'async' | 'fifo';
    label: 'Async' | 'FIFO';
    duration: number;
    entries: TimelineEntry[];
}

interface JourneyStep {
    kind: 'travel' | 'arrivalRing';
    segment?: SegmentKey;
    dest?: 1 | 2 | 3;
    start: number;
    end: number;
    easing: EasingName;
}

// Every event plays this shape, in both modes, offset only by a start time.
// Branch 2/3 deliberately start later and run longer than branch 1 so the
// fan-out reads as an uneven, organic ripple rather than three things
// popping on the same frame.
const EVENT_JOURNEY: JourneyStep[] = [
    {
        kind: 'travel',
        segment: 'ingest-junction',
        start: 0,
        end: 780,
        easing: 'inout',
    },
    {
        kind: 'travel',
        segment: 'junction-dest1',
        start: 780,
        end: 1430,
        easing: 'inout',
    },
    {
        kind: 'travel',
        segment: 'junction-dest2',
        start: 850,
        end: 1530,
        easing: 'inout',
    },
    {
        kind: 'travel',
        segment: 'junction-dest3',
        start: 925,
        end: 1630,
        easing: 'inout',
    },
    { kind: 'arrivalRing', dest: 1, start: 1430, end: 2330, easing: 'out' },
    { kind: 'arrivalRing', dest: 2, start: 1530, end: 2430, easing: 'out' },
    { kind: 'arrivalRing', dest: 3, start: 1630, end: 2530, easing: 'out' },
];

// Global pacing multiplier. The first build ran the full two-phase loop in
// 6.1s, which cycled the legend often enough that the label switching read as
// flicker. Every duration below is authored at the original tempo and scaled
// here, so pacing stays a single knob rather than 20 scattered numbers.
const TIME_SCALE = 2.5;

// The event highlight spans arrival to departure: it fades in when the event
// lands, holds for however long that event waits, then drains left-to-right as
// it dispatches. The hold's *length* is the whole point — under Async it is
// brief, under FIFO the second event holds until the first has fully settled,
// and that difference is the modes' difference made visible. Real milliseconds,
// deliberately outside TIME_SCALE: these are "long enough to read" beats, not
// part of the motion's tempo.
// Shared rise time for any node lighting up — an ingest node when its event
// lands, and a destination when a delivery reaches it. Both ends of a journey
// light identically; only what follows differs (a hold then a drain at the
// ingest end, a long decay at the destination end).
const NODE_GLOW_IN_MS = 300;
const ARRIVAL_IN_MS = NODE_GLOW_IN_MS;
const ARRIVAL_OUT_MS = 380;

// The two webhooks do not land together — one follows the other closely, in
// both modes. What differs afterwards is how long each then waits.
const ARRIVAL_STAGGER = 420;

// Event 1 departs once its own fade-in and a short hold have played.
const PENDING_LEAD = 940;

// How long Event 1's whole journey takes, in scaled ms — Event 2 waits exactly
// this long under FIFO, which is the zero-gap handoff the mode actually promises.
const EVENT_SETTLE = 1960 * TIME_SCALE;

// Event 2 leaves while Event 1 is still on its ingest leg, so both left-hand
// wires carry a pulse at once — but far enough behind that they read as two
// separate events rather than one wide pulse. 150ms was too tight and looked
// like a single synchronized pair.
const ASYNC_OFFSET = 330 * TIME_SCALE;

// One highlight envelope per event: `start` is when the event lands, `end` is
// when it departs, so the drain hands off directly to the pulse leaving the node.
function arrivalFor(
    event: 1 | 2,
    landsAt: number,
    departsAt: number,
): TimelineEntry[] {
    return [{ event, kind: 'queued', start: landsAt, end: departsAt }];
}

function buildEventJourney(event: 1 | 2, offset: number): TimelineEntry[] {
    return EVENT_JOURNEY.map((step): TimelineEntry => {
        if (step.kind === 'travel') {
            return {
                event,
                kind: 'travel',
                segment: step.segment as SegmentKey,
                start: step.start * TIME_SCALE + offset,
                end: step.end * TIME_SCALE + offset,
                easing: step.easing,
            };
        }

        return {
            event,
            kind: 'arrivalRing',
            dest: step.dest as 1 | 2 | 3,
            start: step.start * TIME_SCALE + offset,
            end: step.end * TIME_SCALE + offset,
            easing: step.easing,
        };
    });
}

const ASYNC_SCHEMA: Schema = {
    id: 'async',
    label: 'Async',
    duration: 2850 * TIME_SCALE + PENDING_LEAD,
    entries: [
        { event: 1, kind: 'queued', start: 0, end: PENDING_LEAD },
        {
            event: 2,
            kind: 'queued',
            start: 0,
            end: PENDING_LEAD + ASYNC_OFFSET,
        },
        ...buildEventJourney(1, PENDING_LEAD),
        ...buildEventJourney(2, PENDING_LEAD + ASYNC_OFFSET),
    ],
};

// Event 2 stays queued at its own ingest node until Event 1 has fully settled —
// the one place this diagram is exact rather than organic, because the zero gap
// is the truthful claim.
const FIFO_SCHEMA: Schema = {
    id: 'fifo',
    label: 'FIFO',
    duration: 4500 * TIME_SCALE + PENDING_LEAD,
    entries: [
        ...arrivalFor(1, 0, PENDING_LEAD),
        ...arrivalFor(2, ARRIVAL_STAGGER, PENDING_LEAD + EVENT_SETTLE),
        ...buildEventJourney(1, PENDING_LEAD),
        ...buildEventJourney(2, PENDING_LEAD + EVENT_SETTLE),
    ],
};

const TOTAL_LOOP = ASYNC_SCHEMA.duration + FIFO_SCHEMA.duration; // ~24.2s at TIME_SCALE 2.5

// ---------------------------------------------------------------------------
// Easing — three named cubic-beziers, used everywhere in this illustration
// ---------------------------------------------------------------------------

// A small cubic-bezier timing-function solver (the standard Newton's-method
// + bisection-fallback approach browsers use for CSS `cubic-bezier()`) —
// native canvas has no equivalent of CSS easing, so this is the minimum
// math needed to honor the spec's named curves without a library.
function cubicBezierEasing(
    p1x: number,
    p1y: number,
    p2x: number,
    p2y: number,
): (x: number) => number {
    const a = (aa1: number, aa2: number) => 1 - 3 * aa2 + 3 * aa1;
    const b = (aa1: number, aa2: number) => 3 * aa2 - 6 * aa1;
    const c = (aa1: number) => 3 * aa1;

    const calc = (t: number, aa1: number, aa2: number) =>
        ((a(aa1, aa2) * t + b(aa1, aa2)) * t + c(aa1)) * t;

    const slope = (t: number, aa1: number, aa2: number) =>
        3 * a(aa1, aa2) * t * t + 2 * b(aa1, aa2) * t + c(aa1);

    function tForX(x: number): number {
        let t = x;

        for (let i = 0; i < 8; i++) {
            const currentSlope = slope(t, p1x, p2x);

            if (Math.abs(currentSlope) < 1e-6) {
                break;
            }

            const currentX = calc(t, p1x, p2x) - x;
            t -= currentX / currentSlope;
            t = Math.min(1, Math.max(0, t));
        }

        return t;
    }

    return (x: number): number => {
        if (x <= 0) {
            return 0;
        }

        if (x >= 1) {
            return 1;
        }

        return calc(tForX(x), p1y, p2y);
    };
}

const easeInOut = cubicBezierEasing(0.65, 0, 0.35, 1);
const easeOut = cubicBezierEasing(0.22, 1, 0.36, 1);
const easeDecay = cubicBezierEasing(0.32, 0, 0.67, 0);

function ease(name: EasingName, t: number): number {
    if (name === 'out') {
        return easeOut(t);
    }

    if (name === 'decay') {
        return easeDecay(t);
    }

    return easeInOut(t);
}

// Derived rule: a segment's connection line brightens over the first 150ms
// the pulse occupies it, holds hot while the pulse travels, then releases
// back to idle over 320ms on the decay curve (lingers near peak, then falls
// away — not an instant reset). Returns 0 (idle) .. 1 (hot).
function heatEnvelope(localT: number, start: number, end: number): number {
    const attack = 150;
    const release = 320;

    if (localT < start) {
        return 0;
    }

    if (localT < start + attack) {
        return ease('out', (localT - start) / attack);
    }

    if (localT < end) {
        return 1;
    }

    if (localT < end + release) {
        return 1 - ease('decay', (localT - end) / release);
    }

    return 0;
}

// ---------------------------------------------------------------------------
// Geometry (fractions of canvas logical width/height, per spec's Geometry
// table) and paths (Junction→Destination is a quadratic Bézier, control
// point pulled ~15% from the straight-line midpoint toward the canvas's
// vertical center — a soft outward curve rather than a sharp elbow)
// ---------------------------------------------------------------------------

interface Point {
    x: number;
    y: number;
}

type PathSpec =
    | { kind: 'line'; p0: Point; p1: Point }
    | { kind: 'quad'; p0: Point; p1: Point; c: Point };

function pointOnPath(path: PathSpec, t: number): Point {
    if (path.kind === 'line') {
        return {
            x: path.p0.x + (path.p1.x - path.p0.x) * t,
            y: path.p0.y + (path.p1.y - path.p0.y) * t,
        };
    }

    const mt = 1 - t;

    return {
        x: mt * mt * path.p0.x + 2 * mt * t * path.c.x + t * t * path.p1.x,
        y: mt * mt * path.p0.y + 2 * mt * t * path.c.y + t * t * path.p1.y,
    };
}

interface PathTablePoint {
    t: number;
    x: number;
    y: number;
    len: number;
}

interface PathTable {
    points: PathTablePoint[];
    totalLength: number;
}

// Sampled once per resize (endpoints move with the diagram's continuous
// scaling) so the pulse's 64px tail can walk backward by real arc length
// rather than by the (non-uniform-speed) Bézier parameter.
function buildPathTable(path: PathSpec, samples = 48): PathTable {
    const points: PathTablePoint[] = [];
    let prev = pointOnPath(path, 0);
    let cumulative = 0;
    points.push({ t: 0, x: prev.x, y: prev.y, len: 0 });

    for (let i = 1; i <= samples; i++) {
        const t = i / samples;
        const p = pointOnPath(path, t);
        cumulative += Math.hypot(p.x - prev.x, p.y - prev.y);
        points.push({ t, x: p.x, y: p.y, len: cumulative });
        prev = p;
    }

    return { points, totalLength: cumulative };
}

function lengthAtT(table: PathTable, t: number): number {
    const clamped = Math.min(1, Math.max(0, t));
    const lastIndex = table.points.length - 1;
    const idx = clamped * lastIndex;
    const lo = Math.floor(idx);
    const hi = Math.min(lastIndex, lo + 1);
    const frac = idx - lo;

    return (
        table.points[lo].len +
        (table.points[hi].len - table.points[lo].len) * frac
    );
}

function pointAtLength(table: PathTable, targetLength: number): Point {
    const target = Math.min(table.totalLength, Math.max(0, targetLength));
    const pts = table.points;
    let lo = 0;
    let hi = pts.length - 1;

    while (lo < hi - 1) {
        const mid = (lo + hi) >> 1;

        if (pts[mid].len < target) {
            lo = mid;
        } else {
            hi = mid;
        }
    }

    const span = pts[hi].len - pts[lo].len;
    const frac = span > 0 ? (target - pts[lo].len) / span : 0;

    return {
        x: pts[lo].x + (pts[hi].x - pts[lo].x) * frac,
        y: pts[lo].y + (pts[hi].y - pts[lo].y) * frac,
    };
}

function quadControlPoint(p0: Point, p1: Point, canvasHeight: number): Point {
    const straightMidY = (p0.y + p1.y) / 2;
    const canvasCenterY = canvasHeight / 2;

    return {
        x: (p0.x + p1.x) / 2,
        y: straightMidY + (canvasCenterY - straightMidY) * 0.15,
    };
}

interface Geometry {
    width: number;
    height: number;
    scale: number;
    ingest: [Point, Point];
    junction: [Point, Point];
    dest: [Point, Point, Point];
    nodeW: number;
    nodeH: number;
    junctionR: number;
    cornerR: number;
}

// `scale` is the 560px-reference clamp factor (Responsive Behavior) applied
// to every px value in the charge-pulse/line/ring table — node *positions*
// and *sizes* are plain fractions of the live canvas and need no separate
// scaling, since a fraction is already continuous.
function computeGeometry(width: number, height: number): Geometry {
    const scale = Math.min(1, Math.max(0.55, width / 560));
    const nodeW = width * 0.13;
    const nodeH = height * 0.14;

    return {
        width,
        height,
        scale,
        ingest: [
            { x: width * 0.1, y: height * 0.28 },
            { x: width * 0.1, y: height * 0.72 },
        ],
        // One shared junction, not one per event. Two junctions each fanning to
        // the same three destinations produced six crossing lines — a tangle
        // that read as noise. A single fan point is also the truthful shape:
        // a proxy's destination set is one set, whichever event is passing.
        junction: [
            { x: width * 0.45, y: height * 0.5 },
            { x: width * 0.45, y: height * 0.5 },
        ],
        dest: [
            { x: width * 0.87, y: height * 0.18 },
            { x: width * 0.87, y: height * 0.5 },
            { x: width * 0.87, y: height * 0.82 },
        ],
        nodeW,
        nodeH,
        junctionR: height * 0.012,
        cornerR: Math.min(nodeW, nodeH) * 0.2,
    };
}

interface SegmentPaths {
    spec: PathSpec;
    table: PathTable;
}

type EventPaths = Record<SegmentKey, SegmentPaths>;

function buildEventPaths(eventIndex: 0 | 1, geo: Geometry): EventPaths {
    const ingestPoint = geo.ingest[eventIndex];
    const junctionPoint = geo.junction[eventIndex];
    // Wires meet a node's edge, never its centre. Running them to the centre
    // drew the pulse straight through the node's label.
    const edge = geo.nodeW / 2;

    const ingestJunction: PathSpec = {
        kind: 'line',
        p0: { x: ingestPoint.x + edge, y: ingestPoint.y },
        p1: junctionPoint,
    };

    const toDest = geo.dest.map((destPoint): PathSpec => {
        const endPoint = { x: destPoint.x - edge, y: destPoint.y };

        return {
            kind: 'quad',
            p0: junctionPoint,
            p1: endPoint,
            c: quadControlPoint(junctionPoint, endPoint, geo.height),
        };
    });

    const build = (spec: PathSpec): SegmentPaths => ({
        spec,
        table: buildPathTable(spec),
    });

    return {
        'ingest-junction': build(ingestJunction),
        'junction-dest1': build(toDest[0]),
        'junction-dest2': build(toDest[1]),
        'junction-dest3': build(toDest[2]),
    };
}

// ---------------------------------------------------------------------------
// Theme tokens — read at runtime via getComputedStyle, re-read on every
// theme change; no hex is ever written in code.
// ---------------------------------------------------------------------------

interface Tokens {
    card: string;
    border: string;
    primary: string;
    mutedForeground: string;
    accentFrom: string;
    accentTo: string;
}

function readTokens(): Tokens {
    const styles = getComputedStyle(document.documentElement);

    return {
        card: styles.getPropertyValue('--card').trim(),
        border: styles.getPropertyValue('--border').trim(),
        primary: styles.getPropertyValue('--primary').trim(),
        mutedForeground: styles.getPropertyValue('--muted-foreground').trim(),
        accentFrom: styles.getPropertyValue('--illustration-from').trim(),
        accentTo: styles.getPropertyValue('--illustration-to').trim(),
    };
}

// The only place a numeric alpha is combined with a token — always an
// opacity of an existing color, never a new one.
function withAlpha(hslString: string, alpha: number): string {
    const match = /hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\s*\)/.exec(
        hslString,
    );

    if (!match) {
        return hslString;
    }

    const [, h, s, l] = match;

    return `hsl(${h} ${s}% ${l}% / ${alpha})`;
}

// Dark and light carry separate numeric values throughout (per spec's
// "Charge pulse, line, grid, and node rendering" table) — never the same
// figures reused with a token swap. All px-shaped values here are at the
// 560px reference width and get `* geometry.scale` at draw time.
interface ThemeVisuals {
    gridLineAlpha: number;
    idleLineWidth: number;
    idleLineAlpha: number;
    hotLineWidth: number;
    hotLineAlpha: number;
    pulseCoreWidth: number;
    pulseTailLength: number;
    bloomBlur: number;
    bloomAlpha: number;
    arrivalEdgeWidth: number;
    arrivalEdgeAlpha: number;
    arrivalEdgeBlur: number;
    queuedEdgeAlpha: number;
    nodeStrokeAlpha: number;
    labelAlpha: number;
}

const DARK_VISUALS: ThemeVisuals = {
    gridLineAlpha: 0.16,
    idleLineWidth: 1.5,
    idleLineAlpha: 0.22,
    hotLineWidth: 2,
    hotLineAlpha: 0.3,
    pulseCoreWidth: 5.5,
    pulseTailLength: 82,
    bloomBlur: 20,
    bloomAlpha: 0.55,
    arrivalEdgeWidth: 2,
    arrivalEdgeAlpha: 0.95,
    arrivalEdgeBlur: 18,
    queuedEdgeAlpha: 0.8,
    nodeStrokeAlpha: 0.45,
    labelAlpha: 0.9,
};

const LIGHT_VISUALS: ThemeVisuals = {
    gridLineAlpha: 0.3,
    idleLineWidth: 1.5,
    idleLineAlpha: 0.3,
    hotLineWidth: 2,
    hotLineAlpha: 0.4,
    pulseCoreWidth: 5,
    pulseTailLength: 78,
    bloomBlur: 8,
    bloomAlpha: 0.26,
    arrivalEdgeWidth: 2,
    arrivalEdgeAlpha: 0.9,
    arrivalEdgeBlur: 8,
    queuedEdgeAlpha: 0.85,
    nodeStrokeAlpha: 0.9,
    labelAlpha: 1,
};

const JUNCTION_ALPHA = 0.4; // same both themes — static anchor, always drawn
const NODE_STROKE_WIDTH = 1;
const GRID_CELL = 32; // fixed logical px — does not scale with the diagram

// ---------------------------------------------------------------------------
// Render state — plain objects outside Vue's reactivity; recomputed on
// resize/theme change, read every rAF frame.
// ---------------------------------------------------------------------------

interface RenderState {
    ctx: CanvasRenderingContext2D;
    width: number;
    height: number;
    dpr: number;
    geo: Geometry;
    paths: [EventPaths, EventPaths];
    tokens: Tokens;
    isDark: boolean;
    visuals: ThemeVisuals;
    gridLayer: HTMLCanvasElement;
}

function buildGridLayer(
    width: number,
    height: number,
    dpr: number,
    borderColor: string,
    gridAlpha: number,
): HTMLCanvasElement {
    const layer = document.createElement('canvas');
    layer.width = Math.round(width * dpr);
    layer.height = Math.round(height * dpr);

    const ctx = layer.getContext('2d');

    if (!ctx) {
        return layer;
    }

    const cell = GRID_CELL * dpr;
    ctx.strokeStyle = withAlpha(borderColor, gridAlpha);
    ctx.lineWidth = 1;
    ctx.beginPath();

    for (let x = 0; x <= layer.width; x += cell) {
        ctx.moveTo(x + 0.5, 0);
        ctx.lineTo(x + 0.5, layer.height);
    }

    for (let y = 0; y <= layer.height; y += cell) {
        ctx.moveTo(0, y + 0.5);
        ctx.lineTo(layer.width, y + 0.5);
    }

    ctx.stroke();

    // Edge-fade mask. A circular mask sized off the *shorter* side left the
    // grid as a blob floating in the middle of a wide canvas; this scales the
    // circle into an ellipse matching the canvas aspect, so the grid fills the
    // field and only softens as it approaches the edges.
    const cx = layer.width / 2;
    const cy = layer.height / 2;
    const radius = layer.width * 0.62;

    ctx.globalCompositeOperation = 'destination-in';
    ctx.save();
    ctx.translate(cx, cy);
    ctx.scale(1, layer.height / layer.width);

    const gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, radius);
    gradient.addColorStop(0, 'rgba(0, 0, 0, 1)');
    gradient.addColorStop(0.55, 'rgba(0, 0, 0, 1)');
    gradient.addColorStop(0.8, 'rgba(0, 0, 0, 0.55)');
    gradient.addColorStop(1, 'rgba(0, 0, 0, 0)');
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.arc(0, 0, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
    ctx.globalCompositeOperation = 'source-over';

    return layer;
}

function drawRoundedRect(
    ctx: CanvasRenderingContext2D,
    center: Point,
    w: number,
    h: number,
    r: number,
    fill: string,
    stroke: string,
    strokeWidth: number,
) {
    const x = center.x - w / 2;
    const y = center.y - h / 2;
    ctx.beginPath();
    ctx.roundRect(x, y, w, h, r);
    ctx.fillStyle = fill;
    ctx.fill();
    ctx.lineWidth = strokeWidth;
    ctx.strokeStyle = stroke;
    ctx.stroke();
}

function strokeSegment(
    ctx: CanvasRenderingContext2D,
    spec: PathSpec,
    width: number,
    color: string,
) {
    ctx.beginPath();
    ctx.moveTo(spec.p0.x, spec.p0.y);

    if (spec.kind === 'line') {
        ctx.lineTo(spec.p1.x, spec.p1.y);
    } else {
        ctx.quadraticCurveTo(spec.c.x, spec.c.y, spec.p1.x, spec.p1.y);
    }

    ctx.lineCap = 'round';
    ctx.lineWidth = width;
    ctx.strokeStyle = color;
    ctx.stroke();
}

// Additive compositing for anything that emits light, so overlapping pulses and
// the junction genuinely accumulate brightness instead of painting over each
// other. Dark only: on a white field `lighter` drives everything to white and
// the illustration washes out, so light theme composites normally.
function glowBlend(state: RenderState): GlobalCompositeOperation {
    return state.isDark ? 'lighter' : 'source-over';
}

// Node labels. Drawn into the canvas with the app's own resolved font stack so
// they match the page rather than approximating it. The illustration is
// aria-hidden and the surrounding prose carries the meaning, so this text is
// decorative — it exists to stop the nodes reading as blank boxes.
function drawNodeLabel(
    ctx: CanvasRenderingContext2D,
    center: Point,
    text: string,
    color: string,
    fontPx: number,
) {
    ctx.save();
    ctx.font = `500 ${fontPx}px ${DIAGRAM_FONT}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = color;
    applyTracking(ctx, fontPx * 0.12);
    ctx.fillText(text.toUpperCase(), center.x, center.y);
    ctx.restore();
}

// `letterSpacing` is well supported in current Chrome and Safari but not
// universal; where it is missing the text simply renders untracked rather than
// throwing, which is an acceptable degradation for a decorative diagram.
function applyTracking(ctx: CanvasRenderingContext2D, px: number): void {
    if ('letterSpacing' in ctx) {
        ctx.letterSpacing = `${px}px`;
    }
}

// The mode legend, drawn top-left inside the canvas. The active mode sits at
// full weight with the other dimmed beneath it, so a viewer landing mid-loop can
// still see both modes exist.
function drawModeLegend(state: RenderState, activeId: Schema['id']): void {
    const { ctx, geo, tokens } = state;
    const fontPx = Math.round(13 * geo.scale);
    const x = geo.width * 0.035;
    const y = geo.height * 0.08;
    const lineHeight = fontPx * 1.9;

    ctx.save();
    ctx.font = `600 ${fontPx}px ${DIAGRAM_FONT}`;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    applyTracking(ctx, fontPx * 0.18);

    (['async', 'fifo'] as const).forEach((id, i) => {
        const isActive = id === activeId;
        ctx.fillStyle = isActive
            ? withAlpha(tokens.accentFrom, 1)
            : withAlpha(tokens.mutedForeground, 0.4);
        ctx.fillText(id.toUpperCase(), x, y + i * lineHeight);
    });

    ctx.restore();
}

// Numbers dropped — three boxes reading "Destination" is self-evident, and the
// indices were noise. Uppercase monospace with generous tracking reads as a
// technical diagram rather than as body copy set inside a box.
const INGEST_LABELS = ['Event', 'Event'];
const DEST_LABELS = ['Destination', 'Destination', 'Destination'];

const DIAGRAM_FONT =
    'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace';

// A travelling heat band: the wire is at rest everywhere except around the
// charge's current position, where it peaks and then drains off behind. The
// gradient runs p0 to p1, which is an approximation on the quadratic legs but
// visually indistinguishable at these curvatures.
function drawHotSegment(
    state: RenderState,
    spec: PathSpec,
    head: number,
    hot: number,
    color: string,
): void {
    const { ctx, geo, visuals } = state;
    const peakAlpha = visuals.hotLineAlpha * hot;
    const width =
        (visuals.idleLineWidth +
            (visuals.hotLineWidth - visuals.idleLineWidth) * hot) *
        geo.scale;

    // Outside the lit band the gradient is fully transparent, so the base grey
    // wire shows through untouched. Ending the band at the idle accent alpha
    // instead tinted the whole pipe the instant the segment went hot, which
    // read as the wire switching on rather than lighting along its length.
    const TRAIL = 0.34;
    const LEAD = 0.05;
    const gradient = ctx.createLinearGradient(
        spec.p0.x,
        spec.p0.y,
        spec.p1.x,
        spec.p1.y,
    );

    const trailStop = Math.min(0.998, Math.max(0, head - TRAIL));
    const headStop = Math.min(0.999, Math.max(trailStop + 0.001, head));
    const leadStop = Math.min(1, headStop + LEAD);

    if (trailStop > 0) {
        gradient.addColorStop(0, withAlpha(color, 0));
    }

    gradient.addColorStop(trailStop, withAlpha(color, 0));
    // A soft shoulder partway up the trail keeps the band from reading as a
    // hard-edged wipe.
    gradient.addColorStop(
        trailStop + (headStop - trailStop) * 0.55,
        withAlpha(color, peakAlpha * 0.45),
    );
    gradient.addColorStop(headStop, withAlpha(color, peakAlpha));

    if (leadStop < 1) {
        gradient.addColorStop(leadStop, withAlpha(color, 0));
        gradient.addColorStop(1, withAlpha(color, 0));
    }

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(state);
    ctx.beginPath();
    ctx.moveTo(spec.p0.x, spec.p0.y);

    if (spec.kind === 'line') {
        ctx.lineTo(spec.p1.x, spec.p1.y);
    } else {
        ctx.quadraticCurveTo(spec.c.x, spec.c.y, spec.p1.x, spec.p1.y);
    }

    ctx.lineCap = 'round';
    ctx.lineWidth = width;
    ctx.strokeStyle = gradient;
    ctx.stroke();
    ctx.restore();
}

// Base scene: grid, idle connection lines, resting junction dots, and every
// node — drawn every frame before any per-entry overlay, and the entirety
// of the reduced-motion static frame (see drawEventArrival below).
function drawBaseScene(state: RenderState) {
    const { ctx, geo, paths, tokens, visuals } = state;
    ctx.clearRect(0, 0, state.width, state.height);
    ctx.drawImage(state.gridLayer, 0, 0, state.width, state.height);

    const idleColor = withAlpha(tokens.mutedForeground, visuals.idleLineAlpha);
    const idleWidth = visuals.idleLineWidth * geo.scale;

    for (const eventPaths of paths) {
        for (const key of Object.keys(eventPaths) as SegmentKey[]) {
            strokeSegment(ctx, eventPaths[key].spec, idleWidth, idleColor);
        }
    }

    const junctionColor = withAlpha(tokens.mutedForeground, JUNCTION_ALPHA);

    for (const junction of geo.junction) {
        ctx.beginPath();
        ctx.arc(junction.x, junction.y, geo.junctionR, 0, Math.PI * 2);
        ctx.fillStyle = junctionColor;
        ctx.fill();
    }

    const nodeStroke = withAlpha(
        tokens.mutedForeground,
        visuals.nodeStrokeAlpha,
    );
    const nodeStrokeWidth = NODE_STROKE_WIDTH * geo.scale;

    const labelColor = withAlpha(tokens.mutedForeground, visuals.labelAlpha);
    const fontPx = Math.round(11 * geo.scale);

    geo.ingest.forEach((ingest, i) => {
        drawRoundedRect(
            ctx,
            ingest,
            geo.nodeW,
            geo.nodeH,
            geo.cornerR,
            tokens.card,
            nodeStroke,
            nodeStrokeWidth,
        );
        drawNodeLabel(ctx, ingest, INGEST_LABELS[i], labelColor, fontPx);
    });

    geo.dest.forEach((dest, i) => {
        drawRoundedRect(
            ctx,
            dest,
            geo.nodeW,
            geo.nodeH,
            geo.cornerR,
            tokens.card,
            nodeStroke,
            nodeStrokeWidth,
        );
        drawNodeLabel(ctx, dest, DEST_LABELS[i], labelColor, fontPx);
    });
}

// A queued event lights its own node's border, the mirror of what a
// destination does on arrival: violet edge = waiting to dispatch, cyan edge =
// received. `progress` runs 0 → 1 across the queued window so the border can
// ease in when the event starts waiting and release just before it departs,
// rather than snapping on and off.
// The highlight marks the moment an event *arrives*, not the whole time it
// waits. It fades in, holds briefly, then drains left-to-right into the pipe —
// after which the node sits idle until its dispatch. Holding the border lit for
// the entire queued window made a FIFO straggler glow for five unbroken seconds,
// which read as a stuck state rather than an event landing.
function drawEventArrival(
    state: RenderState,
    event: 1 | 2,
    elapsed: number,
    duration: number,
): void {
    if (elapsed < 0 || elapsed > duration) {
        return;
    }

    const { ctx, geo, tokens, visuals } = state;
    const center = geo.ingest[event - 1];
    const x = center.x - geo.nodeW / 2;
    const y = center.y - geo.nodeH / 2;
    // Fade in, hold for as long as this event actually waits, drain out. The
    // drain is a fixed beat measured back from departure, so a FIFO straggler
    // that waited five seconds hands off at the same speed as one that waited
    // half a second.
    const drainStart = Math.max(ARRIVAL_IN_MS, duration - ARRIVAL_OUT_MS);
    const draining = elapsed > drainStart;
    const fadeIn = Math.min(1, elapsed / ARRIVAL_IN_MS);
    const baseAlpha = visuals.queuedEdgeAlpha * ease('out', fadeIn);

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(state);
    ctx.beginPath();
    ctx.roundRect(x, y, geo.nodeW, geo.nodeH, geo.cornerR);
    ctx.lineWidth = visuals.arrivalEdgeWidth * geo.scale;

    if (!draining) {
        ctx.strokeStyle = withAlpha(tokens.accentFrom, baseAlpha);
        ctx.shadowBlur = visuals.arrivalEdgeBlur * 0.6 * geo.scale * fadeIn;
    } else {
        // Drains left-to-right, as though the charge held in the node is being
        // drawn out into the pipe leaving its right edge.
        const sweep = ease(
            'inout',
            Math.min(1, (elapsed - drainStart) / (duration - drainStart)),
        );
        const SOFT = 0.22;
        // The gradient axis runs wider than the node so the lit band can travel
        // fully off the right edge at constant width. Clamping the stops to the
        // node's own width made the band compress into a vanishing sliver over
        // the last fifth of the sweep — it read as the glow switching off at the
        // edge rather than being drawn out into the pipe.
        const PAD = SOFT;
        const axisStart = x - geo.nodeW * PAD;
        const axisWidth = geo.nodeW * (1 + PAD * 2);
        const gradient = ctx.createLinearGradient(
            axisStart,
            y,
            axisStart + axisWidth,
            y,
        );

        // Map the sweep into the padded axis, then travel a full band-width past
        // the far end so the trailing edge clears the node.
        const span = 1 / (1 + PAD * 2);
        const headPos = PAD * span + sweep * (1 + PAD) * span;
        const soft = SOFT * span;
        const remaining = baseAlpha * (1 - sweep * 0.35);

        const stops: Array<[number, number]> = [
            [0, 0],
            [headPos - soft, 0],
            [headPos, remaining],
            [1, remaining],
        ];

        let previous = -1;

        for (const [at, alpha] of stops) {
            const clamped = Math.min(1, Math.max(0, at));

            if (clamped <= previous) {
                continue;
            }

            previous = clamped;
            gradient.addColorStop(clamped, withAlpha(tokens.accentFrom, alpha));
        }

        ctx.strokeStyle = gradient;
        ctx.shadowBlur =
            visuals.arrivalEdgeBlur * 0.6 * geo.scale * (1 - sweep);
    }

    ctx.shadowColor = withAlpha(tokens.accentFrom, visuals.bloomAlpha * 0.7);
    ctx.stroke();
    ctx.restore();
}

function drawArrivalRingAndWash(
    state: RenderState,
    entry: ArrivalRingEntry,
    localT: number,
) {
    const { ctx, geo, tokens, visuals } = state;
    const elapsed = localT - entry.start;
    const duration = entry.end - entry.start;
    const dest = geo.dest[entry.dest - 1];

    // Edge glow only. The node's own border brightens to the accent and blooms,
    // then eases back — nothing expands outward and the interior is untouched.
    // The earlier expanding ring read as an explosion at each destination,
    // which fought the calm of the travelling pulse.
    //
    // The rise uses the same NODE_GLOW_IN_MS ease as an ingest node lighting on
    // arrival, so both ends of a journey light up identically. Previously a
    // destination snapped to full on the arriving frame while the ingest node
    // ramped, and the mismatch was visible whenever both were on screen.
    const rise = Math.min(1, elapsed / NODE_GLOW_IN_MS);
    const glow =
        elapsed < NODE_GLOW_IN_MS
            ? ease('out', rise)
            : 1 -
              ease(
                  entry.easing,
                  Math.min(
                      1,
                      Math.max(
                          0,
                          (elapsed - NODE_GLOW_IN_MS) /
                              (duration - NODE_GLOW_IN_MS),
                      ),
                  ),
              );
    const x = dest.x - geo.nodeW / 2;
    const y = dest.y - geo.nodeH / 2;

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(state);
    ctx.beginPath();
    ctx.roundRect(x, y, geo.nodeW, geo.nodeH, geo.cornerR);
    ctx.lineWidth = visuals.arrivalEdgeWidth * geo.scale;
    ctx.strokeStyle = withAlpha(
        tokens.accentFrom,
        visuals.arrivalEdgeAlpha * glow,
    );
    ctx.shadowBlur = visuals.arrivalEdgeBlur * geo.scale * glow;
    ctx.shadowColor = withAlpha(tokens.accentFrom, visuals.bloomAlpha * glow);
    ctx.stroke();
    ctx.restore();
}

// Drawn as a single continuous stroke with a gradient `strokeStyle` (not
// discrete alpha-stepped mini-segments) — a stepped approach reads as a
// row of beads rather than one current-like streak, which is exactly the
// "shooting ball" look this redesign exists to fix. The gradient's stops
// follow the same `1 − out(t)` ramp the spec specifies; bloom is applied to
// the whole stroke in one shadow pass, which concentrates naturally near
// the bright head since the tail's alpha is already low there.
function drawPulse(
    state: RenderState,
    table: PathTable,
    headT: number,
    headColor: string,
    tailColor: string,
    bloomColor: string,
) {
    const { ctx, geo, visuals } = state;
    const lineWidth = visuals.pulseCoreWidth * geo.scale;
    const tailLength = visuals.pulseTailLength * geo.scale;
    const headLen = lengthAtT(table, headT);
    const headPoint = pointAtLength(table, headLen);
    const tailPoint = pointAtLength(table, headLen - tailLength);

    const gradient = ctx.createLinearGradient(
        headPoint.x,
        headPoint.y,
        tailPoint.x,
        tailPoint.y,
    );
    const stops = 16;

    // frac 0 = head, frac 1 = tail end. The head burns cyan and the trail
    // cools through violet as it fades — the colour shift does as much work as
    // the alpha ramp in making this read as current rather than a moving shape.
    for (let i = 0; i <= stops; i++) {
        const frac = i / stops;
        const alpha = 1 - ease('out', frac);
        const hue = frac < 0.55 ? headColor : tailColor;
        gradient.addColorStop(frac, withAlpha(hue, alpha));
    }

    const samples = 20;
    ctx.beginPath();
    ctx.moveTo(headPoint.x, headPoint.y);

    for (let i = 1; i <= samples; i++) {
        const frac = i / samples;
        const point = pointAtLength(table, headLen - frac * tailLength);
        ctx.lineTo(point.x, point.y);
    }

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(state);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = lineWidth;
    ctx.strokeStyle = gradient;
    ctx.shadowBlur = visuals.bloomBlur * geo.scale;
    ctx.shadowColor = bloomColor;
    ctx.stroke();
    ctx.restore();
}

// One motion-safe animated frame: idle base scene, then every active entry's
// overlay (event highlights, travelling heat bands, charge pulses, destination
// arrivals) for this schema at this local time.
function drawAnimatedFrame(state: RenderState, schema: Schema, localT: number) {
    drawBaseScene(state);
    drawModeLegend(state, schema.id);

    const { paths, tokens, visuals } = state;
    const headColor = tokens.accentTo;
    const tailColor = tokens.accentFrom;
    const bloomColor = withAlpha(tokens.accentTo, visuals.bloomAlpha);

    for (const entry of schema.entries) {
        if (entry.kind === 'queued') {
            if (localT >= entry.start && localT < entry.end) {
                drawEventArrival(
                    state,
                    entry.event,
                    localT - entry.start,
                    entry.end - entry.start,
                );
            }

            continue;
        }

        const eventPaths = paths[entry.event - 1];

        if (entry.kind === 'travel') {
            // The wire starts lighting slightly before its pulse departs, so the
            // node's draining border and the pipe waking up overlap instead of
            // handing off with a visible seam.
            const PRE_ROLL = 220;
            const hot = heatEnvelope(localT, entry.start - PRE_ROLL, entry.end);
            const active = localT >= entry.start && localT < entry.end;
            const t = active
                ? ease(
                      entry.easing,
                      (localT - entry.start) / (entry.end - entry.start),
                  )
                : localT >= entry.end
                  ? 1
                  : 0;

            if (hot > 0) {
                // The wire does not brighten along its whole length at once —
                // the glow travels with the charge and drains behind it, the
                // same left-to-right release the queued node's border uses.
                // Lighting the entire segment uniformly made the pulse
                // invisible against its own lit path.
                drawHotSegment(
                    state,
                    eventPaths[entry.segment].spec,
                    t,
                    hot,
                    tokens.accentFrom,
                );
            }

            if (active) {
                drawPulse(
                    state,
                    eventPaths[entry.segment].table,
                    t,
                    headColor,
                    tailColor,
                    bloomColor,
                );
            }

            continue;
        }

        // arrivalRing
        if (localT >= entry.start && localT < entry.end) {
            drawArrivalRingAndWash(state, entry, localT);
        }
    }
}

// Reduced-motion fallback: the FIFO-settled moment — Event 1 at rest,
// delivered (idle lines only, no rings/trails), Event 2 shown as the
// static muted queued dot. Not a snapshot of the fifo schema at some T;
// this state has no natural instant in the loop (Event 1's decay tail and
// Event 2's queued window don't share a moment where nothing else is
// mid-flight), so it is composed directly instead.
function drawStaticFrame(state: RenderState) {
    drawBaseScene(state);
    drawModeLegend(state, 'fifo');
    drawEventArrival(state, 2, ARRIVAL_IN_MS + 200, 3000);
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
    container: HTMLElement,
): RenderState | null {
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return null;
    }

    const width = container.clientWidth;
    const height = container.clientHeight;
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    const geo = computeGeometry(width, height);
    const tokens = readTokens();
    const isDark = document.documentElement.classList.contains('dark');
    const visuals = isDark ? DARK_VISUALS : LIGHT_VISUALS;
    // Grid rides on muted-foreground, not --border: on dark, --border is
    // hsl(0 0% 14.9%) against an hsl(0 0% 3.9%) field, so a low-alpha grid drawn
    // in it is invisible.
    const gridLayer = buildGridLayer(
        width,
        height,
        dpr,
        tokens.mutedForeground,
        visuals.gridLineAlpha,
    );

    return {
        ctx,
        width,
        height,
        dpr,
        geo,
        paths: [buildEventPaths(0, geo), buildEventPaths(1, geo)],
        tokens,
        isDark,
        visuals,
        gridLayer,
    };
}

function redrawStatic() {
    if (state) {
        drawStaticFrame(state);
    }
}

function tick(now: number) {
    if (!state) {
        return;
    }

    const elapsed = (now - startTime) % TOTAL_LOOP;
    const isAsync = elapsed < ASYNC_SCHEMA.duration;
    const schema = isAsync ? ASYNC_SCHEMA : FIFO_SCHEMA;
    const localT = isAsync ? elapsed : elapsed - ASYNC_SCHEMA.duration;

    drawAnimatedFrame(state, schema, localT);
    rafId = requestAnimationFrame(tick);
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
        redrawStatic();

        return;
    }

    startTime = performance.now();
    rafId = requestAnimationFrame(tick);
}

function handleResize() {
    const canvas = canvasRef.value;
    const container = containerRef.value;

    if (!canvas || !container) {
        return;
    }

    state = buildRenderState(canvas, container);
    startOrRestart();
}

function handleThemeChange() {
    const canvas = canvasRef.value;
    const container = containerRef.value;

    if (!canvas || !container) {
        return;
    }

    state = buildRenderState(canvas, container);

    if (prefersReducedMotion.value) {
        redrawStatic();
    }
}

function handleMotionPreferenceChange(matches: boolean) {
    prefersReducedMotion.value = matches;
    startOrRestart();
}

onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    prefersReducedMotion.value = motionQuery.matches;
    motionQuery.addEventListener('change', (event) =>
        handleMotionPreferenceChange(event.matches),
    );

    handleResize();

    if (containerRef.value) {
        resizeObserver = new ResizeObserver(() => handleResize());
        resizeObserver.observe(containerRef.value);
    }

    mutationObserver = new MutationObserver(() => handleThemeChange());
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
            The diagram alternates between two processing modes. Async delivers
            an event to every destination at once. FIFO processes one event at a
            time per proxy, in the order received.
        </p>

        <div ref="container" class="mx-auto aspect-[2/1] w-full max-w-6xl">
            <canvas
                ref="canvas"
                aria-hidden="true"
                class="block h-full w-full"
            ></canvas>
        </div>
    </div>
</template>
