# Fix: show-page-destination-remove-affordance

- **Date:** 2026-08-03
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
The proxy Show page (`resources/js/pages/proxies/Show.vue`) had a per-destination
"Remove" button that never deleted anything. Clicking it opened a confirmation
AlertDialog, but confirming did nothing.

Per Owner decision the affordance is being **removed**, not repaired: destination
removal is handled functionally by the proxy **Edit** view, so the Show-page control
is redundant and not a planned roadmap feature.

## Cause
Coupled dialog state — the same class of bug as the earlier Index delete regression
(see git commit `89cfd71 fix: wire proxy Index table delete confirm`, whose decision
record lives in `docs/product/prd-01-index-delete-regression-test-harness.md`).

A single `destinationTarget` ref served **both** as the AlertDialog open-state
(`:open="destinationTarget !== null"`) **and** as the delete target. reka-ui's
`AlertDialogAction` auto-closes the dialog on click, firing
`@update:open(false)` which set `destinationTarget = null` before/around
`confirmRemoveDestination()` read it — so the `if (!target) return` guard bailed and
nothing was deleted.

## Fix
Removed the redundant non-functional Show-page affordance; the Destinations card is
now a read-only method+url list. Destination management remains fully functional in
the proxy Edit view (`DestinationRows.vue` → `DestinationController::destroy`), which
was untouched.

`resources/js/pages/proxies/Show.vue` only:
- Removed the per-destination **Remove** `<Button>` and the "Remove destination
  confirmation" `<AlertDialog>` block.
- Removed the `confirmRemoveDestination()` function and the `destinationTarget` ref.
- Removed the `isLastDestination` computed and its hint block — a read-only list
  cannot violate the min-1-destination invariant, so both were dead. Verified no
  other reference to either symbol.
- Removed the now-unused `destinationRoutes` import and the `ProxyDestination` type
  import (`ProxyDetail`/`ProxyPermissions` still used).
- Kept `busy` (the proxy-delete flow uses it) and every `AlertDialog*` subcomponent
  (all still used by the proxy-delete dialog).

No backend change: the destination destroy route, `DestinationController`, the
destination routes helper, `ProxyController`, and `ProxyPolicy` all stay — the Edit
view depends on them.

A functional **Edit** affordance already existed on the Show page (a `<Link>` to
`proxyRoutes.edit`, gated by `canUpdate = permissions.canUpdateProxy &&
(proxy.is_creator || permissions.canUpdateAnyProxy)`), so removing the inline Remove
does not strand the user. Nothing added there.

## Verification
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.
- `./vendor/bin/sail test`: **223 passed** (865 assertions).
- `pnpm types:check` (vue-tsc): passed.
- `pnpm lint:check` (eslint): passed.
- `pnpm format:check` (prettier): passed.
- No test asserted the Show-page destination-remove UI (no JS test framework exists;
  the frontend regression harness is deferred to T31 per
  `docs/product/prd-01-index-delete-regression-test-harness.md`). `DestinationDestroyTest`
  covers `DestinationController::destroy` directly (still used by Edit) and stays green.

## Follow-ups
None.
