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
    quadControlPoint,
    withAlpha,
} from './canvasKit';
import type { BaseVisuals } from './canvasKit';
import type { EasingName, PathSpec, PathTable, Point } from './canvasKit';
import type { CanvasBase } from './useCanvasIllustration';
import { useCanvasIllustration } from './useCanvasIllustration';

// ---------------------------------------------------------------------------
// Timeline schema (design spec "Timeline schema" section)
// ---------------------------------------------------------------------------

type SegmentKey =
    'ingest-junction' | 'junction-dest1' | 'junction-dest2' | 'junction-dest3';

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

// Sampled once per resize (endpoints move with the diagram's continuous
// scaling) so the pulse's 64px tail can walk backward by real arc length
// rather than by the (non-uniform-speed) Bézier parameter.

interface Geometry {
    width: number;
    height: number;
    scale: number;
    compact: boolean;
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

    // Below this the diagram cannot keep its desktop proportions: node labels
    // are a fixed number of characters, so a node sized as a fraction of a phone
    // viewport is narrower than the word it has to hold. Compact mode widens the
    // nodes, flattens them, and pulls the columns inward rather than just
    // scaling everything down.
    const compact = width < 520;
    const nodeW = width * (compact ? 0.4 : 0.13);
    const nodeH = height * (compact ? 0.12 : 0.14);

    return {
        width,
        height,
        scale,
        compact,
        ingest: [
            {
                x: width * (compact ? 0.22 : 0.1),
                y: height * (compact ? 0.3 : 0.28),
            },
            {
                x: width * (compact ? 0.22 : 0.1),
                y: height * (compact ? 0.7 : 0.72),
            },
        ],
        // One shared junction, not one per event. Two junctions each fanning to
        // the same three destinations produced six crossing lines — a tangle
        // that read as noise. A single fan point is also the truthful shape:
        // a proxy's destination set is one set, whichever event is passing.
        junction: [
            { x: width * 0.5, y: height * 0.5 },
            { x: width * 0.5, y: height * 0.5 },
        ],
        dest: [
            {
                x: width * (compact ? 0.78 : 0.87),
                y: height * (compact ? 0.2 : 0.18),
            },
            { x: width * (compact ? 0.78 : 0.87), y: height * 0.5 },
            {
                x: width * (compact ? 0.78 : 0.87),
                y: height * (compact ? 0.8 : 0.82),
            },
        ],
        nodeW,
        nodeH,
        junctionR: height * (compact ? 0.014 : 0.018),
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

// The only place a numeric alpha is combined with a token — always an
// opacity of an existing color, never a new one.

// Dark and light carry separate numeric values throughout (per spec's
// "Charge pulse, line, grid, and node rendering" table) — never the same
// figures reused with a token swap. All px-shaped values here are at the
// 560px reference width and get `* geometry.scale` at draw time.
// What this diagram needs beyond the shared base: a heated-wire treatment, the
// queued event's edge, and the junction ring.
interface ThemeVisuals extends BaseVisuals {
    hotLineWidth: number;
    hotLineAlpha: number;
    queuedEdgeAlpha: number;
    junctionAlpha: number;
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
    edgeWidth: 2,
    edgeAlpha: 0.95,
    edgeBlur: 18,
    queuedEdgeAlpha: 0.8,
    nodeStrokeAlpha: 0.45,
    labelAlpha: 0.9,
    junctionAlpha: 0.3,
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
    edgeWidth: 2,
    edgeAlpha: 0.9,
    edgeBlur: 8,
    queuedEdgeAlpha: 0.85,
    nodeStrokeAlpha: 0.9,
    labelAlpha: 1,
    junctionAlpha: 0.55,
};

const NODE_STROKE_WIDTH = 1;

// ---------------------------------------------------------------------------
// Render state — plain objects outside Vue's reactivity; recomputed on
// resize/theme change, read every rAF frame.
// ---------------------------------------------------------------------------

interface RenderState extends CanvasBase<ThemeVisuals> {
    geo: Geometry;
    paths: [EventPaths, EventPaths];
}

// Draw the cached grid through a drifting elliptical mask. Four out-of-phase
// sines with non-harmonic periods (4.3s / 3.1s / 5.3s / 6.7s) keep the motion
// from settling into an obvious loop — the fade wanders rather than pulsing on a
// beat. Radius, centre and softness move the mask's geometry; the alpha breath
// does most of the perceptual work, since a brightness change is far easier to
// notice at the edge of vision than a boundary shifting a few pixels.

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

// `letterSpacing` is well supported in current Chrome and Safari but not
// universal; where it is missing the text simply renders untracked rather than
// throwing, which is an acceptable degradation for a decorative diagram.

// The mode legend, drawn top-left inside the canvas. The active mode sits at
// full weight with the other dimmed beneath it, so a viewer landing mid-loop can
// still see both modes exist.
function drawModeLegend(state: RenderState, activeId: Schema['id']): void {
    const { ctx, geo, tokens } = state;
    // On compact the legend sat directly on the first Event node; centre it
    // above the diagram instead, where the taller aspect leaves room.
    const fontPx = Math.round((geo.compact ? 11 : 13) * geo.scale);
    const x = geo.compact ? geo.width * 0.5 : geo.width * 0.035;
    const y = geo.compact ? geo.height * 0.015 : geo.height * 0.08;
    const lineHeight = fontPx * (geo.compact ? 1.6 : 1.9);

    ctx.save();
    ctx.font = `600 ${fontPx}px ${DIAGRAM_FONT}`;
    ctx.textAlign = geo.compact ? 'center' : 'left';
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

// Base scene: grid, idle connection lines, resting junction dots, and every
// node — drawn every frame before any per-entry overlay, and the entirety
// of the reduced-motion static frame (see drawEventArrival below).
// Thin wrappers over the shared primitives, binding this diagram's geometry and
// theme table so the frame code reads as a sequence of marks rather than as
// argument plumbing.
function label(state: RenderState, center: Point, text: string): void {
    const { geo, tokens, visuals } = state;
    const fontPx = labelSizeFor(text, geo.nodeW, 11 * geo.scale);
    drawNodeLabel(
        state.ctx,
        center,
        text,
        withAlpha(tokens.mutedForeground, visuals.labelAlpha),
        fontPx,
        // Tracking is a luxury of having room; on compact it eats the width the
        // label needs.
        fontPx * (geo.compact ? 0.04 : 0.12),
    );
}

function pulse(state: RenderState, table: PathTable, headT: number): void {
    const { geo, visuals, tokens } = state;
    drawPulse(state.ctx, {
        table,
        headT,
        headColor: tokens.accentTo,
        tailColor: tokens.accentFrom,
        coreWidth: visuals.pulseCoreWidth * geo.scale,
        tailLength: visuals.pulseTailLength * geo.scale,
        bloomBlur: visuals.bloomBlur * geo.scale,
        bloomAlpha: visuals.bloomAlpha,
        isDark: state.isDark,
    });
}

function heat(
    state: RenderState,
    spec: PathSpec,
    head: number,
    hot: number,
): void {
    const { geo, visuals, tokens } = state;
    drawHeatBand(state.ctx, {
        spec,
        head,
        peakAlpha: visuals.hotLineAlpha * hot,
        width:
            (visuals.idleLineWidth +
                (visuals.hotLineWidth - visuals.idleLineWidth) * hot) *
            geo.scale,
        color: tokens.accentFrom,
        isDark: state.isDark,
    });
}

function edge(
    state: RenderState,
    center: Point,
    stroke: string | CanvasGradient,
    blur: number,
    shadowColor: string,
): void {
    const { geo, visuals } = state;
    drawNodeEdge(state.ctx, {
        center,
        width: geo.nodeW,
        height: geo.nodeH,
        radius: geo.cornerR,
        stroke,
        lineWidth: visuals.edgeWidth * geo.scale,
        blur,
        shadowColor,
        isDark: state.isDark,
    });
}

function drawBaseScene(state: RenderState, timeMs: number) {
    const { ctx, geo, paths, tokens, visuals } = state;
    ctx.clearRect(0, 0, state.width, state.height);
    compositeGrid(
        ctx,
        state.gridLayer,
        state.scratch,
        state.dpr,
        timeMs,
        state.reducedMotion,
    );

    const idleColor = withAlpha(tokens.mutedForeground, visuals.idleLineAlpha);
    const idleWidth = visuals.idleLineWidth * geo.scale;

    // Each event's ingest leg is its own, but both events share the junction, so
    // their three junction-to-destination segments are geometrically identical.
    // Stroking every event's full path set drew those three twice and compounded
    // their alpha — the fan-out half rendered visibly darker than the ingest
    // half. Draw the ingest legs per event and the shared fan once.
    for (const eventPaths of paths) {
        strokeSegment(
            ctx,
            eventPaths['ingest-junction'].spec,
            idleWidth,
            idleColor,
        );
    }

    for (const key of [
        'junction-dest1',
        'junction-dest2',
        'junction-dest3',
    ] as SegmentKey[]) {
        strokeSegment(ctx, paths[0][key].spec, idleWidth, idleColor);
    }

    // A thin ring, not a filled dot. The solid grey circle was the only opaque
    // blob in an otherwise line-based drawing and read as an artefact sitting on
    // top of the wires rather than as part of them. Drawn once — both events
    // share this junction, so the array holds the same point twice.
    const junctionColor = withAlpha(
        tokens.mutedForeground,
        visuals.junctionAlpha,
    );
    const junction = geo.junction[0];

    ctx.beginPath();
    ctx.arc(junction.x, junction.y, geo.junctionR, 0, Math.PI * 2);
    ctx.lineWidth = NODE_STROKE_WIDTH * geo.scale;
    ctx.strokeStyle = junctionColor;
    ctx.stroke();

    const nodeStroke = withAlpha(
        tokens.mutedForeground,
        visuals.nodeStrokeAlpha,
    );
    const nodeStrokeWidth = NODE_STROKE_WIDTH * geo.scale;

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
        label(state, ingest, INGEST_LABELS[i]);
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
        label(state, dest, DEST_LABELS[i]);
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

    let stroke: string | CanvasGradient;
    let blur: number;

    if (!draining) {
        stroke = withAlpha(tokens.accentFrom, baseAlpha);
        blur = visuals.edgeBlur * 0.6 * geo.scale * fadeIn;
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

        stroke = gradient;
        blur = visuals.edgeBlur * 0.6 * geo.scale * (1 - sweep);
    }

    edge(
        state,
        center,
        stroke,
        blur,
        withAlpha(tokens.accentFrom, visuals.bloomAlpha * 0.7),
    );
}

function drawArrivalRingAndWash(
    state: RenderState,
    entry: ArrivalRingEntry,
    localT: number,
) {
    const { geo, tokens, visuals } = state;
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
    edge(
        state,
        dest,
        withAlpha(tokens.accentFrom, visuals.edgeAlpha * glow),
        visuals.edgeBlur * geo.scale * glow,
        withAlpha(tokens.accentFrom, visuals.bloomAlpha * glow),
    );
}

// One motion-safe animated frame: idle base scene, then every active entry's
// overlay (event highlights, travelling heat bands, charge pulses, destination
// arrivals) for this schema at this local time.
function drawAnimatedFrame(
    state: RenderState,
    schema: Schema,
    localT: number,
    timeMs: number,
) {
    drawBaseScene(state, timeMs);
    drawModeLegend(state, schema.id);

    const { paths } = state;

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
                heat(state, eventPaths[entry.segment].spec, t, hot);
            }

            if (active) {
                pulse(state, eventPaths[entry.segment].table, t);
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
    drawBaseScene(state, 0);
    drawModeLegend(state, 'fifo');
    drawEventArrival(state, 2, ARRIVAL_IN_MS + 200, 3000);
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

// The composable binds the template refs by name (`ref="container"` /
// `ref="canvas"`), so nothing needs to come back out of it here.
useCanvasIllustration<ThemeVisuals, RenderState>({
    visualsFor: (isDark) => (isDark ? DARK_VISUALS : LIGHT_VISUALS),
    gridAlpha: (visuals) => visuals.gridLineAlpha,
    extend: (base) => {
        const geo = computeGeometry(base.width, base.height);

        return {
            ...base,
            geo,
            paths: [buildEventPaths(0, geo), buildEventPaths(1, geo)],
        };
    },
    drawFrame: (state, elapsed) => {
        const local = elapsed % TOTAL_LOOP;
        const isAsync = local < ASYNC_SCHEMA.duration;

        drawAnimatedFrame(
            state,
            isAsync ? ASYNC_SCHEMA : FIFO_SCHEMA,
            isAsync ? local : local - ASYNC_SCHEMA.duration,
            elapsed,
        );
    },
    drawStatic: (state) => drawStaticFrame(state),
});
</script>

<template>
    <div class="flex flex-col items-center">
        <p class="sr-only">
            The diagram alternates between two processing modes. Async delivers
            an event to every destination at once. FIFO processes one event at a
            time per proxy, in the order received.
        </p>

        <div
            ref="container"
            class="mx-auto aspect-square w-full max-w-6xl overflow-hidden rounded-2xl border border-border sm:aspect-[2/1]"
        >
            <canvas
                ref="canvas"
                aria-hidden="true"
                class="block h-full w-full"
            ></canvas>
        </div>
    </div>
</template>
