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

    const cell = GRID_CELL * dpr;

    // Phase the grid so the canvas centre lands in the middle of a cell rather
    // than wherever the dimensions happen to put it. The diagrams place their
    // junction at exact centre, and an unphased grid left it sitting off-square
    // — close enough to alignment to read as a mistake.
    const phase = (centre: number) =>
        (((centre - cell / 2) % cell) + cell) % cell;
    const offsetX = phase(layer.width / 2);
    const offsetY = phase(layer.height / 2);

    ctx.strokeStyle = withAlpha(borderColor, gridAlpha);
    ctx.lineWidth = 1;
    ctx.beginPath();

    for (let x = offsetX - cell; x <= layer.width + cell; x += cell) {
        ctx.moveTo(Math.round(x) + 0.5, 0);
        ctx.lineTo(Math.round(x) + 0.5, layer.height);
    }

    for (let y = offsetY - cell; y <= layer.height + cell; y += cell) {
        ctx.moveTo(0, Math.round(y) + 0.5);
        ctx.lineTo(layer.width, Math.round(y) + 0.5);
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
