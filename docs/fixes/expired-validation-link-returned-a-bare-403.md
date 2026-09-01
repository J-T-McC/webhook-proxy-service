# Fix: an expired validation link returned a bare 403 instead of the Expired screen

- **Date:** 2026-09-01
- **Found by:** the live browser pass on the confirmation page, at the Project Owner's request
- **Item:** #18, destination validation (T12/T13 surface)

## Symptom

Opening a validation link after its challenge had expired produced Laravel's bare
`403 Forbidden` page. design-18 Screen 4 specifies an Expired outcome — "This link has
expired… ask them to send a new one" — and it never rendered. An approver in that
position had no way to learn that a fresh link could be requested.

## Cause

`SendDestinationValidationChallenge` built one timestamp and used it for two different
jobs:

```php
$expiresAt = now()->addDays(config('destination_validation.challenge_ttl_days'));

URL::temporarySignedRoute('destinations.validate.show', $expiresAt, ...);  // middleware gate
$destination->forceFill(['validation_challenge_expires_at' => $expiresAt]); // approval gate
```

The `signed` middleware runs before the controller. With both clocks set to the same
instant, the middleware always refused the request at the moment the challenge lapsed,
so the controller's `expired` branch could not execute under any circumstance. It was
not a narrow race — the branch was unreachable by construction.

The test suite did not catch it because both existing tests mint their own signed URL
with a generous `now()->addDays(7)` expiry rather than the expiry the action actually
uses, so they exercised the controller branch directly and never the real link.

## Fix

Split the two clocks. `destination_validation.link_grace_days` (14) extends the GET
link's signature expiry only.

- `validation_challenge_expires_at` is still set from `challenge_ttl_days` alone. The
  approval deadline does not move, and AC22 is unaffected.
- A click during the grace window now reaches the controller, which reports the expiry
  and renders design-18's Expired screen.
- After the grace window the signature lapses too, and a 403 is the right answer.

**Nothing is approvable during the grace window, and three independent things make that
true.** The approval gate is the stored expiry, which the grace period does not touch.
The GET renders and never mutates (AC28). The POST that performs approval is signed by
the controller against the stored challenge expiry rather than the grace expiry, and is
only minted on the `approvable` branch, so it does not exist on an expired page at all.

## Verified

- Two new tests in `SendDestinationValidationChallengeTest`: the sent link's `expires`
  parameter is exactly the stored expiry plus the grace period, and the stored deadline
  is still set from the challenge TTL alone.
- Two new tests in `DestinationValidationControllerTest`: a link inside its grace window
  renders the `expired` outcome rather than 403ing, and a POST inside that window leaves
  the state `Pending`, stamps no approval and does not burn the nonce.
- Live with Playwright: a link minted the way the action mints it, against a challenge
  that expired two days earlier, renders "This link has expired", names the asking team
  per AC27, and shows no Approve button.

## Also observed during the same pass, not fixed

- The confirmation page's layout heading reads "Approve this destination" on every
  outcome, including "Destination approved" and "This link is not valid". The `<title>`
  is correct in each case; only the in-page heading is static.
- The destination URL wraps mid-token under `break-all`, rendering as `http` /
  `s://webhook.site/…`.
- In `ReplayDialog`, a partially selected "Select all" renders a tick rather than a dash.
  The semantics are right (`data-state="indeterminate"`, `aria-checked="mixed"`); only
  the glyph is wrong, and it lives in the shared `ui/checkbox` component rather than in
  anything #18 introduced.
