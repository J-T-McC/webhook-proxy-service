/**
 * Framework-agnostic display formatters shared by the events list and detail
 * pages (design-06 Screens 2/3) — no existing relative-time/byte-size
 * component to reuse (design-06 Screen 2 note), so a small shared helper
 * avoids duplicating the same formatting twice.
 */

/**
 * An absolute, locale-formatted timestamp (e.g. `Aug 12, 2026, 3:41 PM`) — no
 * relative-time invention, matching this app's plain-timestamp convention
 * elsewhere.
 */
export function formatTimestamp(value: string): string {
    return new Date(value).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB'] as const;

/**
 * A human-readable byte count (e.g. `2.1 KB`). One decimal place for KB and
 * above; whole bytes below 1 KB.
 */
export function formatByteSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < BYTE_UNITS.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(1)} ${BYTE_UNITS[unitIndex]}`;
}
