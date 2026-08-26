// Shared canvas primitives for the landing-page illustrations.
//
// Extracted so the fan-out and reliability diagrams cannot drift apart: they
// share an easing vocabulary, a token reader, path sampling, and the grid
// backdrop. Anything that differs between the two — geometry, timelines,
// per-diagram drawing — stays in the component that owns it.
//
// No hex is ever written here; every colour comes from a CSS custom property
// read at runtime, so a theme change repaints correctly.

export type EasingName = 'inout' | 'out' | 'decay';

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

// Three named curves, shared so both diagrams move with the same vocabulary.
const easeInOut = cubicBezierEasing(0.65, 0, 0.35, 1);
const easeOut = cubicBezierEasing(0.22, 1, 0.36, 1);
const easeDecay = cubicBezierEasing(0.32, 0, 0.67, 0);

export function ease(name: EasingName, t: number): number {
    if (name === 'out') {
        return easeOut(t);
    }

    if (name === 'decay') {
        return easeDecay(t);
    }

    return easeInOut(t);
}

export interface Point {
    x: number;
    y: number;
}

export type PathSpec =
    | { kind: 'line'; p0: Point; p1: Point }
    | { kind: 'quad'; p0: Point; p1: Point; c: Point };

export function pointOnPath(path: PathSpec, t: number): Point {
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

export interface PathTablePoint {
    t: number;
    x: number;
    y: number;
    len: number;
}

export interface PathTable {
    points: PathTablePoint[];
    totalLength: number;
}

export function buildPathTable(path: PathSpec, samples = 48): PathTable {
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

export function lengthAtT(table: PathTable, t: number): number {
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

export function pointAtLength(table: PathTable, targetLength: number): Point {
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

export function quadControlPoint(
    p0: Point,
    p1: Point,
    canvasHeight: number,
): Point {
    const straightMidY = (p0.y + p1.y) / 2;
    const canvasCenterY = canvasHeight / 2;

    return {
        x: (p0.x + p1.x) / 2,
        y: straightMidY + (canvasCenterY - straightMidY) * 0.15,
    };
}

export interface Tokens {
    card: string;
    border: string;
    primary: string;
    mutedForeground: string;
    accentFrom: string;
    accentTo: string;
    destructive: string;
}

export function readTokens(): Tokens {
    const styles = getComputedStyle(document.documentElement);

    return {
        card: styles.getPropertyValue('--card').trim(),
        border: styles.getPropertyValue('--border').trim(),
        primary: styles.getPropertyValue('--primary').trim(),
        mutedForeground: styles.getPropertyValue('--muted-foreground').trim(),
        accentFrom: styles.getPropertyValue('--illustration-from').trim(),
        accentTo: styles.getPropertyValue('--illustration-to').trim(),
        destructive: styles.getPropertyValue('--destructive').trim(),
    };
}

export function withAlpha(hslString: string, alpha: number): string {
    const match = /hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\s*\)/.exec(
        hslString,
    );

    if (!match) {
        return hslString;
    }

    const [, h, s, l] = match;

    return `hsl(${h} ${s}% ${l}% / ${alpha})`;
}

export function buildGridLayer(
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

    // The cell size is derived from the canvas rather than fixed, so the grid
    // divides it into a whole number of cells and the outermost lines land
    // exactly on the border. Two goals have to hold at once and they constrain
    // each other: lines meeting the frame requires a whole cell count, while the
    // junction sitting mid-cell (the diagrams place it at exact centre) requires
    // that count to be ODD. Rounding to the nearest odd count satisfies both and
    // keeps the cell within a few percent of its nominal size — at the common
    // desktop width the drift is imperceptible, and the payoff is a grid that
    // meets its frame cleanly on the screen most people will see.
    const nearestOdd = (n: number) => {
        const rounded = Math.max(1, Math.round(n));

        return rounded % 2 === 0 ? rounded + 1 : rounded;
    };

    const nominal = GRID_CELL * dpr;
    const cols = nearestOdd(layer.width / nominal);
    const rows = nearestOdd(layer.height / nominal);
    const cellX = layer.width / cols;
    const cellY = layer.height / rows;

    ctx.strokeStyle = withAlpha(borderColor, gridAlpha);
    ctx.lineWidth = 1;
    ctx.beginPath();

    for (let i = 0; i <= cols; i++) {
        // Inset the outermost lines by half a pixel so they sit inside the
        // canvas rather than being clipped in half by its edge.
        const x = Math.min(
            layer.width - 0.5,
            Math.max(0.5, Math.round(i * cellX) + 0.5),
        );
        ctx.moveTo(x, 0);
        ctx.lineTo(x, layer.height);
    }

    for (let j = 0; j <= rows; j++) {
        const y = Math.min(
            layer.height - 0.5,
            Math.max(0.5, Math.round(j * cellY) + 0.5),
        );
        ctx.moveTo(0, y);
        ctx.lineTo(layer.width, y);
    }

    ctx.stroke();

    // Deliberately unmasked. The edge fade is applied per frame in
    // `compositeGrid` so its radius, centre and softness can drift; baking it in
    // here would freeze the mask to whatever the layer was built with.
    return layer;
}

export function drawRoundedRect(
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

export const DIAGRAM_FONT =
    'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace';

// `letterSpacing` is well supported in current Chrome and Safari but not
// universal; where it is missing the text renders untracked rather than
// throwing, which is an acceptable degradation for a decorative diagram.
export function applyTracking(ctx: CanvasRenderingContext2D, px: number): void {
    if ('letterSpacing' in ctx) {
        ctx.letterSpacing = `${px}px`;
    }
}

// Additive compositing for anything that emits light, so overlapping glows
// accumulate brightness instead of painting over each other. Dark only: on a
// white field `lighter` drives everything to white and the illustration washes
// out, so light theme composites normally.
export function glowBlend(isDark: boolean): GlobalCompositeOperation {
    return isDark ? 'lighter' : 'source-over';
}

export const GRID_CELL = 32;

// Draw a cached grid through a drifting elliptical mask. Four out-of-phase
// sines with non-harmonic periods keep the motion from settling into an obvious
// loop. The alpha breath does most of the perceptual work — a change in
// luminance is far easier to notice at the edge of vision than a boundary
// moving a few pixels.
export function compositeGrid(
    ctx: CanvasRenderingContext2D,
    gridLayer: HTMLCanvasElement,
    scratch: HTMLCanvasElement,
    dpr: number,
    timeMs: number,
    reducedMotion: boolean,
): void {
    const scratchCtx = scratch.getContext('2d');
    const w = scratch.width;
    const h = scratch.height;

    if (!scratchCtx) {
        ctx.drawImage(gridLayer, 0, 0, w / dpr, h / dpr);

        return;
    }

    scratchCtx.setTransform(1, 0, 0, 1, 0, 0);
    scratchCtx.clearRect(0, 0, w, h);
    scratchCtx.globalCompositeOperation = 'source-over';
    scratchCtx.drawImage(gridLayer, 0, 0, w, h);

    const drift = reducedMotion ? 0 : 1;
    const radiusFactor =
        0.62 +
        drift *
            (0.1 * Math.sin(timeMs / 4300) +
                0.055 * Math.sin(timeMs / 3100 + 1.1));
    const cx = w / 2 + drift * w * 0.035 * Math.sin(timeMs / 5300);
    const cy = h / 2 + drift * h * 0.045 * Math.sin(timeMs / 3100 + 0.6);
    const inner = 0.48 + drift * 0.13 * Math.sin(timeMs / 4300 + 2.2);
    const radius = w * radiusFactor;

    scratchCtx.globalCompositeOperation = 'destination-in';
    scratchCtx.save();
    scratchCtx.translate(cx, cy);
    scratchCtx.scale(1, h / w);

    const mask = scratchCtx.createRadialGradient(0, 0, 0, 0, 0, radius);
    mask.addColorStop(0, 'rgba(0, 0, 0, 1)');
    mask.addColorStop(inner, 'rgba(0, 0, 0, 1)');
    mask.addColorStop((inner + 1) / 2, 'rgba(0, 0, 0, 0.55)');
    mask.addColorStop(1, 'rgba(0, 0, 0, 0)');
    scratchCtx.fillStyle = mask;
    scratchCtx.beginPath();
    scratchCtx.arc(0, 0, radius, 0, Math.PI * 2);
    scratchCtx.fill();
    scratchCtx.restore();

    const breath =
        1 +
        drift *
            (0.22 * Math.sin(timeMs / 6700) +
                0.1 * Math.sin(timeMs / 3100 + 2.7));

    ctx.save();
    ctx.globalAlpha = Math.min(1.35, Math.max(0.6, breath));
    ctx.drawImage(scratch, 0, 0, w / dpr, h / dpr);
    ctx.restore();
}

// ---------------------------------------------------------------------------
// Shared drawing primitives
//
// Both diagrams draw the same marks — a charge pulse, a travelling heat band, a
// node's edge lighting up, a node label. They were written twice and had already
// begun to diverge in ways that were not deliberate (one gained a colour shift
// in its pulse tail that the other never got). What genuinely differs between
// the diagrams is geometry and timeline, not how a mark is drawn.
// ---------------------------------------------------------------------------

/** Per-theme values every diagram needs. Each extends this with its own. */
export interface BaseVisuals {
    gridLineAlpha: number;
    idleLineWidth: number;
    idleLineAlpha: number;
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

export interface PulseOptions {
    table: PathTable;
    /** 0..1 along the path. */
    headT: number;
    headColor: string;
    tailColor: string;
    coreWidth: number;
    tailLength: number;
    bloomBlur: number;
    bloomAlpha: number;
    isDark: boolean;
}

// Drawn as a single continuous stroke with a gradient `strokeStyle`, not as
// discrete alpha-stepped mini-segments — a stepped approach reads as a row of
// beads rather than one current-like streak. The head burns in the leading
// colour and the trail cools through the second as it fades; that colour shift
// does as much work as the alpha ramp in making this read as current rather
// than a moving shape.
export function drawPulse(
    ctx: CanvasRenderingContext2D,
    options: PulseOptions,
): void {
    const headLen = lengthAtT(options.table, options.headT);
    const headPoint = pointAtLength(options.table, headLen);
    const tailPoint = pointAtLength(
        options.table,
        headLen - options.tailLength,
    );

    const gradient = ctx.createLinearGradient(
        headPoint.x,
        headPoint.y,
        tailPoint.x,
        tailPoint.y,
    );
    const stops = 16;

    for (let i = 0; i <= stops; i++) {
        const frac = i / stops;
        gradient.addColorStop(
            frac,
            withAlpha(
                frac < 0.55 ? options.headColor : options.tailColor,
                1 - ease('out', frac),
            ),
        );
    }

    const samples = 20;

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(options.isDark);
    ctx.beginPath();
    ctx.moveTo(headPoint.x, headPoint.y);

    for (let i = 1; i <= samples; i++) {
        const point = pointAtLength(
            options.table,
            headLen - (i / samples) * options.tailLength,
        );
        ctx.lineTo(point.x, point.y);
    }

    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = options.coreWidth;
    ctx.strokeStyle = gradient;
    ctx.shadowBlur = options.bloomBlur;
    ctx.shadowColor = withAlpha(options.headColor, options.bloomAlpha);
    ctx.stroke();
    ctx.restore();
}

export interface HeatBandOptions {
    spec: PathSpec;
    /** 0..1 — where the charge currently is. */
    head: number;
    peakAlpha: number;
    width: number;
    color: string;
    isDark: boolean;
    /** How far behind the head the band fades out, as a fraction of the path. */
    trail?: number;
}

// A travelling heat band: the wire is at rest everywhere except around the
// charge, where it peaks and drains off behind. Fully transparent outside the
// lit zone so the base wire shows through untouched — ending the band at an idle
// accent alpha instead tinted the whole pipe the instant the segment went hot,
// which read as the wire switching on rather than lighting along its length.
//
// The gradient runs p0 to p1, an approximation on curved paths but visually
// indistinguishable at the curvatures these diagrams use.
export function drawHeatBand(
    ctx: CanvasRenderingContext2D,
    options: HeatBandOptions,
): void {
    const { spec, head, color } = options;
    const trail = options.trail ?? 0.34;
    const LEAD = 0.05;
    const gradient = ctx.createLinearGradient(
        spec.p0.x,
        spec.p0.y,
        spec.p1.x,
        spec.p1.y,
    );

    const trailStop = Math.min(0.998, Math.max(0, head - trail));
    const headStop = Math.min(0.999, Math.max(trailStop + 0.001, head));
    const leadStop = Math.min(1, headStop + LEAD);

    gradient.addColorStop(0, withAlpha(color, 0));
    gradient.addColorStop(trailStop, withAlpha(color, 0));
    // A soft shoulder partway up the trail keeps the band from reading as a
    // hard-edged wipe.
    gradient.addColorStop(
        trailStop + (headStop - trailStop) * 0.55,
        withAlpha(color, options.peakAlpha * 0.45),
    );
    gradient.addColorStop(headStop, withAlpha(color, options.peakAlpha));

    if (leadStop < 1) {
        gradient.addColorStop(leadStop, withAlpha(color, 0));
        gradient.addColorStop(1, withAlpha(color, 0));
    }

    ctx.save();
    ctx.globalCompositeOperation = glowBlend(options.isDark);
    ctx.beginPath();
    ctx.moveTo(spec.p0.x, spec.p0.y);

    if (spec.kind === 'line') {
        ctx.lineTo(spec.p1.x, spec.p1.y);
    } else {
        ctx.quadraticCurveTo(spec.c.x, spec.c.y, spec.p1.x, spec.p1.y);
    }

    ctx.lineCap = 'round';
    ctx.lineWidth = options.width;
    ctx.strokeStyle = gradient;
    ctx.stroke();
    ctx.restore();
}

export interface NodeEdgeOptions {
    center: Point;
    width: number;
    height: number;
    radius: number;
    /** A colour, or a gradient when the edge drains directionally. */
    stroke: string | CanvasGradient;
    lineWidth: number;
    blur: number;
    shadowColor: string;
    isDark: boolean;
}

// A node's own border lighting up — used for a delivery arriving, an event
// waiting to dispatch, and a terminal failure. Nothing expands outward: an
// earlier expanding ring read as an explosion and fought the calm of the pulse.
export function drawNodeEdge(
    ctx: CanvasRenderingContext2D,
    options: NodeEdgeOptions,
): void {
    ctx.save();
    ctx.globalCompositeOperation = glowBlend(options.isDark);
    ctx.beginPath();
    ctx.roundRect(
        options.center.x - options.width / 2,
        options.center.y - options.height / 2,
        options.width,
        options.height,
        options.radius,
    );
    ctx.lineWidth = options.lineWidth;
    ctx.strokeStyle = options.stroke;
    ctx.shadowBlur = options.blur;
    ctx.shadowColor = options.shadowColor;
    ctx.stroke();
    ctx.restore();
}

// Node labels. Tracked uppercase monospace: body copy set inside diagram boxes
// read as body copy, monospace reads as a schematic. The illustrations are
// aria-hidden with surrounding prose carrying the meaning, so this text is
// decorative — it exists so the nodes do not read as blank boxes.
export function drawNodeLabel(
    ctx: CanvasRenderingContext2D,
    center: Point,
    text: string,
    color: string,
    fontPx: number,
    tracking: number,
): void {
    ctx.save();
    ctx.font = `500 ${fontPx}px ${DIAGRAM_FONT}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = color;
    applyTracking(ctx, tracking);
    ctx.fillText(text.toUpperCase(), center.x, center.y);
    ctx.restore();
}

// The longest label a node has to hold decides its type size, so a label can
// never overflow its box at any viewport. In tracked uppercase monospace a glyph
// advances at roughly 0.72em.
export function labelSizeFor(
    text: string,
    nodeWidth: number,
    nominalPx: number,
): number {
    const maxByWidth = (nodeWidth * 0.82) / (text.length * 0.72);

    return Math.max(7, Math.round(Math.min(nominalPx, maxByWidth)));
}
