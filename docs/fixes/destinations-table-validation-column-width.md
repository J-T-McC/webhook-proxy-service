# Fix: the Validation column was crushed to 117px and rows ran 310px tall

- **Date:** 2026-09-01
- **Reported by:** Project Owner, from the live page
- **Item:** #18, destination validation (T15/T16/T20 surface)

## Symptom

On the proxy Show page, the Destinations table's Validation column was very narrow,
its caption wrapped to eight lines, and each row was roughly 310px tall. The Actions
column was pushed past the container's right edge.

## Cause

Not the caption length, which was the obvious suspect. The table used the browser's
default automatic layout, which allocates column width by content. A destination URL
is one long unbreakable token, so the Destination column won the negotiation outright
— 559px of 1148px measured on the reported page — and the Validation column, whose
content is the only wrapping content on the row, was left with 117px.

The `truncate` class on the URL never fired for two compounding reasons: automatic
layout grew the column rather than clipping the text, and the URL `span` is a flex
item without `min-w-0`, so it would not shrink below its content width even in a
bounded cell.

## Fix

- The table declares `table-fixed` and a `min-w-[72rem]` minimum, with explicit
  per-column widths (Destination 35%, Validation 27%, the three metric columns and
  Actions taking the rest). Below the minimum the table container's existing
  `overflow-x-auto` scrolls it, which is how this table already behaved on narrow
  viewports and which the Project Owner confirmed as acceptable.
- `min-w-0` added to the URL span, so `truncate` actually produces an ellipsis.
- `max-w-64` removed from the validation cell, which capped it below its new column.
- Separately, at the Owner's direction, the state captions were shortened. AC34
  reserves wording and presentation to the Designer and freezes only the obligation,
  and the Designer gate was dropped for this item, so no PRD amendment was needed.
  design-18's state table and its Screen 2 responsive paragraph were both updated to
  match.

## Verified

Playwright against the running application at 1440px and at 360px, with all four
validation states plus a recorded send failure and a recorded response status. Row
heights fell from 307px and 319px to 131px and 111px, and to 59px for a Validated
row. At 360px the table scrolls inside its own container and the page body does not
scroll horizontally. The proxy edit form, which reuses the same captions, was checked
separately.
