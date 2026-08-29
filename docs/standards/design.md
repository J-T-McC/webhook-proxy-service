# Design Standards

> **Status: Proposed — pending Project Owner approval.** Owned by the Designer
> (proposes) and Project Owner (approves). Sections below codify patterns this
> frontend already ships (Tailwind CSS 4 tokens in `resources/css/app.css`, Reka
> UI primitives wrapped in `resources/js/components/ui/`, and the Inertia
> interaction patterns in `InviteMemberModal.vue`, `RemoveMemberModal.vue`, and
> the proxies Index/Show delete flows); rules with no existing precedent are
> tagged **Proposed default (no prior precedent)** so the Owner ratifies the
> observed patterns and decides the genuinely new ones. There is currently **no
> frontend test framework** (`docs/standards/coding.md` → Dependencies; backlog
> item T31), so every rule below is enforced by code review and the
> Designer→Product Manager design-gate, not by automated tests.

## Requirements (active)
- Every UI-bearing user story maps to a flow in the design spec (plugin `templates/design-spec.md`); every screen documents its states (default, empty, loading, error, success).
- Reuse documented components and patterns before introducing new ones; new components are flagged in the spec.
- Accessibility expectations are stated per screen, not assumed.

## Design system

**Styling system (codifies observed):** Tailwind CSS 4 (`tailwindcss` ^4.3.3,
`@tailwindcss/vite`), configured entirely in CSS via `resources/css/app.css` —
no `tailwind.config.*` file exists. Tokens are CSS custom properties on
`:root`/`.dark`, re-exposed to Tailwind utilities through an `@theme inline`
block (`--color-background` → `bg-background`, etc.). Do not hardcode raw color
values (`#fff`, `hsl(...)`) in components; use the semantic Tailwind utility
that maps to a token (`bg-card`, `text-muted-foreground`, `border-destructive`).

**Color tokens (codifies observed):** semantic pairs only —
`background`/`foreground`, `card`/`card-foreground`, `popover`/`popover-foreground`,
`primary`/`primary-foreground`, `secondary`/`secondary-foreground`,
`muted`/`muted-foreground`, `accent`/`accent-foreground`,
`destructive`/`destructive-foreground`, plus `border`, `input`, `ring`,
`chart-1..5`, and a parallel `sidebar-*` set for the app shell. There is no raw
brand palette beyond these — adding a token means adding both a `:root` and
`.dark` value plus its `@theme inline` mapping, never a one-off hex in a
component.

**Dark mode (codifies observed):** class-based, `.dark` selector via
`@custom-variant dark (&:is(.dark *))`, toggled by `useAppearance()`
(`resources/js/composables/useAppearance.ts`) with three states — `light` /
`dark` / `system` — persisted to `localStorage` and a cookie (for SSR), applied
via `document.documentElement.classList.toggle('dark', ...)` and initialized on
boot (`initializeTheme()` in `app.ts`). **Every screen and every new component
must be designed and reviewed against both palettes**; there is no light-only
or dark-only surface in the app today.

**Radius scale (codifies observed):** one variable, `--radius: 0.5rem`, derives
`--radius-lg` (`var(--radius)`), `--radius-md` (`-2px`), `--radius-sm` (`-4px`).
Use the `rounded-lg`/`rounded-md`/`rounded-sm` utilities that map to these —
don't pick an arbitrary `rounded-[Npx]`.

**Typography (Proposed default — no prior precedent):** there is no custom type
scale in `@theme` — only one font family is declared (`--font-sans: Instrument
Sans, ui-sans-serif, system-ui, ...`) and pages use Tailwind's stock
`text-sm`/`text-lg`/`text-xl`/`font-medium`/`font-semibold` utilities directly
(e.g. `Show.vue` page heading is `text-xl font-semibold`, card headings are
`<h2 class="text-base font-semibold">`, helper/error text is `text-sm`).
Proposed default: keep using Tailwind's default type scale as-is rather than
introduce a bespoke one — page titles `text-xl font-semibold`, section/card
headings `text-base font-semibold` (the `Show.vue` precedent above — this
applies equally to a `fieldset`/`legend` standing in for a card's own heading
when the card has no sibling `h2`, per `design-17`'s 2026-08-29 amendment),
sub-headings nested *inside* an already-headed card (e.g. a `legend` grouping
a subsection of fields under a card that already carries its own `h2`)
`text-sm font-medium`, body/help/error text `text-sm`, secondary text pairs
with `text-muted-foreground`.

**Spacing (Proposed default — no prior precedent):** no spacing scale override
in `@theme` — Tailwind 4's default 4px-based scale is used as-is (`gap-2`/`gap-3`/
`gap-4`/`gap-6`, `p-4`/`p-6`/`p-10`, `space-y-6`/`space-y-10` observed across
pages). Proposed default: continue relying on the Tailwind default scale;
observed increments in this app are `2`/`3`/`4` for tight/related-control gaps,
`6` for card/section padding and stacked-section spacing, `10` for top-level
page-section stacks (`teams/Edit.vue`).

**Component library (codifies observed):** Reka UI (`reka-ui` ^2.10.1, headless
Vue primitives) wrapped in local, generated components under
`resources/js/components/ui/<name>/` (shadcn-vue-style: one wrapper per
primitive part — e.g. `alert-dialog/AlertDialogContent.vue`,
`dialog/DialogHeader.vue`). **These wrappers are generated code and must never
be hand-edited** (`docs/standards/coding.md` → Project structure); regenerate
via the shadcn-vue/Reka UI generator rather than patching in place.
Application-level composition (`InviteMemberModal.vue`, `RemoveMemberModal.vue`,
`DestinationRows.vue`, `CopyField.vue`, `InputError.vue`) lives hand-written in
`resources/js/components/*`, built from `ui/*` primitives — compose existing
`ui/*` primitives before adding a new one; a genuinely new primitive still goes
through the generator, not a hand-written `ui/` file.

**Variant pattern (codifies observed):** component-level style variants use
`class-variance-authority` (`cva`) plus the project's `cn()` helper (`clsx` +
`tailwind-merge`, `resources/js/lib/utils.ts`) to merge caller overrides
safely. `Button` is the reference: `buttonVariants = cva(base, { variants:
{ variant: {...}, size: {...} }, defaultVariants: {...} })`, exported alongside
a `ButtonVariants` type via `VariantProps`. Any new component needing style
variants follows this same `cva` + exported `*Variants` type +
`defaultVariants` shape — don't hand-roll a `v-if`/ternary class chain for
variants.

**Icons (codifies observed):** `@lucide/vue` is the only icon set in use
(`Loader2Icon`, `X`, `ChevronDown`, `Mail`, `UserPlus`, etc.) — don't introduce
a second icon library.

## Interaction patterns

**Forms (codifies observed; see `docs/standards/coding.md` → Error handling):**
two established, equivalent patterns, chosen by whether the page needs to
touch form state before submit:
- **Inertia `<Form>` component**, bound to a generated Wayfinder action
  (`v-bind="update.form(team.slug)"`, `v-bind="storeInvitation.form(...)"`),
  with `v-slot="{ errors, processing }"` — for a form that's a direct,
  unmediated post of its fields (`teams/Edit.vue` name form,
  `InviteMemberModal.vue`).
- **`useForm()`** composable — for a form that needs client-side state
  before submit (`ProxyForm.vue`'s destination-row add/remove, the `onError`
  focus-management callback, auth pages).

Default to `<Form>`; reach for `useForm()` only when the page needs
programmatic access to form state. Both read validation errors from the
Inertia error bag (`form.errors` / the `errors` slot prop) — never invent a
parallel client-side validation/error state.

**Validation feedback (codifies observed + coding.md):** every validated field
renders its error via the shared `InputError` component (`text-sm
text-red-600 dark:text-red-500`, `v-show`n on presence) placed immediately
after the field. The field sets `:aria-invalid="errors.x ? 'true' :
undefined"` and `aria-describedby` pointing at both its help-text id and its
error id (`aria-describedby="name-help name-error"`). On a failed submit,
focus moves programmatically to the first `[aria-invalid="true"]` field
(`nextTick` + `querySelector`, see `ProxyForm.vue`, `Login.vue`) — required for
every multi-field form, not optional polish.

**Confirmation dialogs — destructive actions (ratifies the design-01-approved
pattern as the binding standard):** every destructive action (delete/remove)
opens an **`AlertDialog`** (`components/ui/alert-dialog/*`, Reka UI
`AlertDialog*`), never a bare click-to-delete or a plain `Dialog`:
- `AlertDialogTitle` names the target (`Delete "{name}"?`);
  `AlertDialogDescription` states the concrete, irreversible consequence (see
  the proxy-delete copy in `Index.vue`/`Show.vue`).
- `AlertDialogFooter` always orders **Cancel** (`AlertDialogCancel`, the safe,
  default-focused action) before the destructive **Confirm**
  (`AlertDialogAction`, styled `bg-destructive text-white
  hover:bg-destructive/90`).
- The confirm button is `:disabled` for the duration of the request (a local
  `deleting`/`busy` ref) to prevent double-submit; the dialog's `open` boolean
  is a **ref separate from the delete target** (`deleteOpen` vs
  `deleteTarget`) — do not derive `open` solely from `target !== null` for a
  row-scoped delete, per the regression fixed in `89cfd71`/`19e73c7`
  (`docs/status.md` item #1). Reset the target only in `onFinish`.
- **Known gap:** `RemoveMemberModal.vue` (predates the design-01 AlertDialog
  convention) uses a plain `Dialog` for a destructive action (removing a team
  member). This standard adopts **AlertDialog for all destructive
  confirmations** as the binding convention going forward;
  `RemoveMemberModal.vue` is legacy, not a pattern to copy, and should be
  migrated opportunistically rather than reused as a template.

**Non-destructive dialogs (codifies observed):** modal forms that create/edit
(not delete) use the plain **`Dialog`** (`InviteMemberModal.vue`):
`DialogHeader`/`DialogTitle`/`DialogDescription`, `DialogFooter` with
`DialogClose as-child` (Cancel) + submit button, closed on `@success` from the
Inertia `<Form>`.

**Loading / disabled states (codifies observed):** every submit control
disables itself for the duration of its request (`:disabled="processing"` / a
local `busy`/`deleting`/`loading` ref) — the non-negotiable floor for every
mutation. Where the request may be perceptibly slow (auth flows today), an
inline `Spinner` (`ui/spinner`, wraps `Loader2Icon`, `role="status"
aria-label="Loading"`) renders before the button label (`Login.vue` et al.).
**Proposed default (no prior precedent):** extend the `Spinner` to all submit
buttons for consistency — the proxies/teams flows today only disable, without
the icon; not yet binding since it isn't universal, but new work should
include it. There is no page-level content-loading skeleton pattern (the
`Skeleton` primitive is only used inside the generated sidebar) because this
app has no client-side async data fetching after mount — page loads are full
Inertia visits, surfaced by Inertia's own progress bar (`progress: { color:
'#4B5563' }` in `app.ts`), not a bespoke spinner/skeleton.

**Success / error feedback (codifies observed + coding.md):** the sole
notification channel is a **Sonner** toast (`vue-sonner`) driven by
`initializeFlashToast()`, which listens for the Inertia `flash` router event
and reads the shared `flash.toast` prop (`{ type, message }`) set server-side
via `Inertia::flash('toast', [...])`. `type` must be a valid `sonner` method
(`success`/`error`/...). Do not build a parallel toast/banner mechanism, and do
not `throw` in a component for an expected server error — render it through
the error bag or the flash channel.

**Affordance visibility (ratified by Owner direction 2026-08-03; ADR-009
Amendment B):** whether an action affordance (button/menu item/control)
renders is decided **client-side**, from the current user's already-shared
permission set plus fields already present on the record — never a per-record
round trip or a per-record server-side authorization call. The stateful
Inertia/Jetstream frontend already exposes the user's roles/permissions; use
them.
- Example shape (proxies pattern): `perms.<action> && (record.is_creator ||
  perms.<action>Any)`.
- **Invariant:** this governs **display only**. Server-side policy remains the
  authoritative gate for the action itself — hiding an affordance is never a
  substitute for server authorization.

## Accessibility baseline

**Target level (Owner-approved 2026-08-03):** **WCAG 2.1 AA** is the target for
all new UI. No level was previously stated in any PRD, ADR, or the approved
design-01 spec (which spoke only of a "functional accessibility minimum" —
labels, focus, keyboard, screen-reader-announced copy;
`docs/design/design-01-walking-skeleton.md`). 2.1 AA matches the concrete
practices already shipped (label association, focus management, token-driven
contrast, keyboard operability) and is ratified as the standard.

**Keyboard support (codifies observed + Reka UI defaults):**
- Every interactive control is reachable and operable via keyboard alone
  (Tab/Shift+Tab, Enter/Space to activate, Esc to dismiss overlays) — e.g. the
  full create-proxy flow (fields, add/remove destination rows, submit) is
  required to work pointer-free (design-01 AC).
- Focus-visible styling is token-driven (`focus-visible:ring-ring/50`,
  `focus-visible:border-ring`, base-layer `outline-ring/50`) applied
  consistently through `buttonVariants` and the shared input styles — never
  suppress the focus ring.
- `AlertDialog`/`Dialog` (Reka UI) provide focus trap, Esc-to-close, and
  return focus to the trigger on close out of the box — rely on this rather
  than re-implementing it; `Select`/`DropdownMenu` provide arrow-key/
  roving-tabindex navigation per the WAI-ARIA APG patterns Reka UI implements.
- On validation failure, focus moves to the first invalid field (see
  Interaction patterns); on add/remove of a repeatable row, focus moves to the
  new field or a sensible neighbour (design-01 AC) — apply this to any future
  repeatable-row UI.

**Screen-reader requirements (codifies observed):**
- Every input has a programmatically associated `Label` (`for`/`id`), never a
  placeholder-only label.
- Help and error text are linked via `aria-describedby`, not just visually
  adjacent.
- Every icon-only or ambiguous-target control (Delete/Remove buttons, copy
  button) carries a discernible `aria-label` naming its target (`Delete proxy
  {name}`, `Remove destination {url}`) — never rely on a bare icon.
- Dialog/AlertDialog content supplies both a `*Title` and `*Description`
  (Reka UI expects — and warns in dev if missing — a labelled/described
  dialog); this also satisfies screen-reader announcement of purpose on open.
- Status/loading indicators use `role="status"` with an `aria-label`
  (`Spinner`); result feedback that isn't purely visual (e.g. "Copied") should
  use an `aria-live="polite"` region, not colour/icon alone (design-01
  pattern — extend to any future async-feedback control).
- Colour is never the sole carrier of meaning (badges pair colour with text;
  error state pairs colour with `InputError` text plus `aria-invalid`).

**Enforcement (honest gap):** there is **no automated accessibility testing** —
no frontend test framework exists yet (`docs/standards/coding.md`; backlog
T31 covers only a future delete-regression test, not a11y). Every rule above is
enforced by **design-spec review and code review only**; a design spec must
state accessibility expectations per screen (per this doc's Requirements
section) and the PM design-gate checks the spec against them, not a running
test.

## Responsive targets

**Breakpoints (codifies observed):** unmodified Tailwind 4 default scale — no
override in `@theme` — `sm` 640px, `md` 768px, `lg` 1024px, `xl` 1280px, `2xl`
1536px. The app shell's own mobile/desktop split uses `md`
(`useMediaQuery("(max-width: 768px)")` in `SidebarProvider.vue`) to switch the
sidebar from a persistent rail to an off-canvas `Sheet`.

**Form factor stance (ratifies the design-01-approved default):**
**desktop-first, degrading gracefully** — this is a developer/operator tool
(webhook proxy configuration and monitoring), not a consumer mobile app, and no
PRD to date scopes a specific form factor. Design for desktop first; ensure
narrow viewports remain usable, not necessarily optimized. A future feature's
PRD may override this per-feature; absent that, this is the default.

**Established patterns to reuse (codifies observed):**
- **Tables** (`ui/table`) have no responsive stacking variant — they sit in a
  horizontally-scrollable container on narrow viewports rather than reflowing
  to cards (`proxies/Index.vue`). A stacked-card fallback is optional, not
  required, unless a PRD calls for a mobile-optimized list.
- **Forms** are single-column, centered, width-capped (`max-w-2xl`/`max-w-3xl
  mx-auto`); a field that's fixed-width on larger screens goes full-width
  below `sm` (`Select` trigger `w-full sm:w-64` in `ProxyForm.vue`), never the
  reverse.
- **Detail/card layouts** stack vertically; any field that could overflow
  (e.g. the ingest URL) scrolls horizontally within its own card rather than
  breaking the page layout (`CopyField` usage in `Show.vue`).
- **App shell** (sidebar/nav) responsiveness is entirely inherited from the
  generated `ui/sidebar` primitives — collapsing to a `Sheet` below `md` — do
  not redesign it per-feature.

**Minimum supported width (Proposed default — no prior precedent):** no
minimum viewport width is documented or tested. Proposed default: treat
**360px** (the common mobile-web floor) as the practical minimum to remain
usable (not optimized) at, consistent with "degrade gracefully" — this is not
derived from any existing test or PRD and should be confirmed or replaced by
the Owner if a real target device/width matters for this product.

**Enforcement:** as with accessibility, there is no responsive/visual
regression test tooling; conformance is checked by design-spec review
(per-screen states in the design spec) and code/PM review, not automated
tests.
