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

Shortening the captions alone does not fix this. Measured with the shortened copy in
place and no width intervention, the split was still 559px to 117px and a
one-sentence caption still wrapped to four lines: automatic layout treats wrapping
text as infinitely compressible, so the column that can wrap always loses to the one
that cannot.

## Fix

Applied to the column that causes the problem rather than the one that suffers it,
after an intermediate attempt that did the opposite.

- **The destination URL is capped at `20rem` and truncated, with the full value in a
  tooltip** — the treatment `ReplayDialog` already gives a destination URL, reused
  rather than reinvented. Capping what that column can demand leaves the remainder to
  be distributed normally. **No column width is declared anywhere**, and the table no
  longer needs to scroll at a typical desktop width.
- `min-w-0` on the URL span, without which `truncate` cannot fire at all.
- `max-w-64` removed from the validation cell.
- **Rejected on the way:** `table-fixed` with declared per-column widths and a
  `min-w-[72rem]` table minimum. It worked — rows fell to 131px — but the Project
  Owner did not want fixed widths, and it treated the symptom rather than the cause.
  Reverted.

Separately, and at the Owner's direction across three passes, the state captions were
cut hard. AC34 reserves wording and presentation to the Designer and freezes only the
obligation, and the Designer gate was dropped for this item, so no PRD amendment was
needed.

- Validated and Unvalidated-never-sent carry no caption at all: neither asks anything
  the badge — and, for the latter, the Validate button beside it — does not already
  say.
- Pending's standing "Sending again cancels the current link" was removed, on the
  Owner's reasoning that a member who is re-validating does not have the previous link
  in front of them anyway.
- Expired keeps only its date. The badge means "nobody approved in time", and "send a
  new one" is what the button beside it is.
- The three failure reasons and the three rate-limit descriptions were shortened.
- Pending keeps a clause naming who must act and the expiry, because AC34 spells that
  state out by name. A failed send keeps its reason, because that is the distinction
  AC35 exists to make.

design-18's state table, its failure-reason copy, its rate-limited line and its Screen
2 responsive paragraph were all updated to match.

## Verified

Playwright against the running application at 1440px and at 360px, with all four
validation states plus a recorded send failure and a recorded response status.

- Row heights fell from 307px and 319px to 127px and 111px; a Validated row is an
  ordinary single-line table row.
- The Destination column fell from 559px to roughly 395px and the Validation column
  rose from 117px to roughly 200px, which is what removed the wrapping.
- The whole table now fits a 1070px container without scrolling, so the Actions column
  is no longer pushed past the right edge.
- At 360px the table scrolls inside its own container and the page body does not
  scroll horizontally.
- The tooltip was confirmed to serve the full URL for a truncated row.
- The proxy edit form, which reuses the same captions, was checked separately.
