import type { DataOption } from '@/types/data';

/**
 * Single source of truth for the closed set of per-proxy processing modes —
 * shared by the proxy form (select options), the detail page (badge label), and
 * the index list (column label), so the same mode reads identically everywhere.
 *
 * Values MUST stay in sync with the PHP `ProcessingMode` enum and the validation
 * rule `Rule::enum(ProcessingMode::class)` in `StoreProxyRequest` /
 * `UpdateProxyRequest`; the backend is authoritative (do not add a value here
 * without adding the enum case first).
 */
export const PROXY_PROCESSING_MODES = [
    { value: 'async', label: 'Async' },
    { value: 'fifo', label: 'FIFO' },
] as const satisfies readonly DataOption<string>[];

/**
 * Per-proxy processing mode — the value union derived from
 * {@link PROXY_PROCESSING_MODES} (currently `'async' | 'fifo'`).
 */
export type ProcessingMode = (typeof PROXY_PROCESSING_MODES)[number]['value'];

/**
 * The label for a processing mode value, from the shared const so no bare mode
 * literal appears in the UI. Falls back to the raw value for an unknown mode.
 */
export function proxyProcessingModeLabel(mode: ProcessingMode): string {
    return (
        PROXY_PROCESSING_MODES.find((option) => option.value === mode)?.label ??
        mode
    );
}
