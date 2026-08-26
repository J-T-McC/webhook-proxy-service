import { onMounted, onUnmounted, ref, useTemplateRef } from 'vue';
import { buildGridLayer, readTokens } from '@/components/welcome/canvasKit';
import type { Tokens } from '@/components/welcome/canvasKit';

// ---------------------------------------------------------------------------
// Lifecycle shared by the landing-page canvas illustrations.
//
// Both diagrams need the same things and got them wrong in the same ways while
// they were being built: a device-pixel-ratio-aware backing store, a grid layer
// and scratch canvas rebuilt on resize, theme tokens re-read when the theme
// changes (a canvas that caches its palette at init looks correct until someone
// toggles), a reduced-motion branch that never starts the loop, and teardown of
// the frame loop and every observer on unmount.
//
// Duplicating that across two components meant a fix to one silently left the
// other stale. This owns it once; each component supplies only what is actually
// its own — the per-theme visual table, the geometry, and how to draw a frame.
// ---------------------------------------------------------------------------

/** The parts of a render state this composable builds and owns. */
export interface CanvasBase<TVisuals> {
    ctx: CanvasRenderingContext2D;
    width: number;
    height: number;
    dpr: number;
    tokens: Tokens;
    isDark: boolean;
    visuals: TVisuals;
    gridLayer: HTMLCanvasElement;
    scratch: HTMLCanvasElement;
    reducedMotion: boolean;
}

export interface CanvasIllustrationOptions<TVisuals, TState> {
    /** Per-theme visual table for this diagram. */
    visualsFor: (isDark: boolean) => TVisuals;
    /** Alpha the grid backdrop is drawn at, taken from the visual table. */
    gridAlpha: (visuals: TVisuals) => number;
    /**
     * Extend the shared base with whatever this diagram needs — geometry,
     * pre-sampled paths, anything derived from the canvas size.
     */
    extend: (base: CanvasBase<TVisuals>) => TState;
    /** Draw one animated frame. `elapsed` is milliseconds since the loop began. */
    drawFrame: (state: TState, elapsed: number) => void;
    /** Draw the single frame shown when the viewer prefers reduced motion. */
    drawStatic: (state: TState) => void;
}

export function useCanvasIllustration<TVisuals, TState>(
    options: CanvasIllustrationOptions<TVisuals, TState>,
) {
    const containerRef = useTemplateRef<HTMLDivElement>('container');
    const canvasRef = useTemplateRef<HTMLCanvasElement>('canvas');
    const prefersReducedMotion = ref(false);

    let state: TState | null = null;
    let rafId: number | null = null;
    let startTime = 0;
    let resizeObserver: ResizeObserver | null = null;
    let mutationObserver: MutationObserver | null = null;
    let motionQuery: MediaQueryList | null = null;

    function build(): TState | null {
        const canvas = canvasRef.value;
        const container = containerRef.value;

        if (!canvas || !container) {
            return null;
        }

        const ctx = canvas.getContext('2d');
        const width = container.clientWidth;
        const height = container.clientHeight;

        if (!ctx || width === 0 || height === 0) {
            return null;
        }

        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        const tokens = readTokens();
        const isDark = document.documentElement.classList.contains('dark');
        const visuals = options.visualsFor(isDark);

        // Scratch layer for the per-frame grid mask. Reused rather than
        // allocated each frame; rebuilt only on resize or theme change.
        const scratch = document.createElement('canvas');
        scratch.width = Math.round(width * dpr);
        scratch.height = Math.round(height * dpr);

        return options.extend({
            ctx,
            width,
            height,
            dpr,
            tokens,
            isDark,
            visuals,
            // The grid rides on muted-foreground, not --border: on dark,
            // --border is hsl(0 0% 14.9%) against an hsl(0 0% 3.9%) field, so a
            // low-alpha grid drawn in it is invisible.
            gridLayer: buildGridLayer(
                width,
                height,
                dpr,
                tokens.mutedForeground,
                options.gridAlpha(visuals),
            ),
            scratch,
            reducedMotion: prefersReducedMotion.value,
        });
    }

    function tick(now: number) {
        if (!state) {
            return;
        }

        options.drawFrame(state, now - startTime);
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
            options.drawStatic(state);

            return;
        }

        startTime = performance.now();
        rafId = requestAnimationFrame(tick);
    }

    function rebuild() {
        state = build();
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

        // Theme changes swap the root element's class; the canvas has to re-read
        // its tokens and repaint or it stays in the previous palette.
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

    return { containerRef, canvasRef, prefersReducedMotion };
}
