# Question Q-08-02: Map-selection matching syntax (roadmap **M2**)

- **Status:** **RESOLVED** (Project Owner, 2026-08-26) — **roadmap M2 is closed**. Folded
  into `docs/product/prd-08-payload-mapping.md` **AC12** (the condition's semantics) and
  new **AC17** (the extensibility requirement, made reviewer-checkable).
- **Raised by:** Product Manager
- **Owner (must answer):** **Project Owner** *(product decision. Raised by the Owner's
  own 2026-07-30 insight; the roadmap states "must be settled before #8's PRD. **Not
  answered here.**" It is a scope/expressiveness decision — how much power a no-code
  audience is given — not a technical one, and the Product Manager will not invent it.)*
- **Raised:** 2026-08-25
- **Gates:** *(when open)* **BLOCKING** for PRD-08 approval — **AC12** of
  `docs/product/prd-08-payload-mapping.md` states the condition exists but cannot state
  its form; and the Designer cannot design the condition control (one key/value pair? an
  operator dropdown? a rule builder?) without it. AC11 and AC16 hold under every option.
- **Source:** `docs/product/roadmap.md` #8 and § Open Questions **M2**: *"How is the
  selecting condition expressed (e.g. the key path and value-match semantics for something
  like `type == "CHARGE"`)?"* Also `vision.md` § What It Must Do (*"matching a key against
  a specific value (e.g. `type == \"CHARGE\"`)"*) and § Target Users (*"technically
  inclined, but served with a **no-code / low-code** experience"*).
- **Related:** `Q-08-01` (M1 — how a winner is picked among matches). Independent: any
  answer here works with any answer there.

## Context
The roadmap and vision both give exactly one example of a selection condition —
`type == "CHARGE"` — and nothing more. Everything a member would actually hit on day one
is undefined: how deep a key path may go (`type` vs `data.object.status`), whether
anything other than exact equality is available, what happens when the key is absent,
whether matching is case-sensitive, and whether one map may carry more than one condition.

The tension to rule on is **expressiveness vs. the no-code promise**. The vision draws a
hard line at "no scripting" and "conditional routing, scripting, or external lookups" are
out of scope; a rich expression language would cross it. But too little power and members
duplicate the same map four times because four Stripe event types share one output shape.

Anything richer than the minimum is also a one-way door for the UI: a single key/value
pair is a two-field control, an operator set is a three-field control, and multiple
combined conditions is a rule builder. Adding power later is cheap; removing it is not.

## Question

### (a) The core expression form

- **Option A — one key path + exact value equality (PM recommendation).** Each
  conditional map carries exactly one condition: a key path into the incoming JSON and a
  single literal value it must equal. Nothing else. *Basis:* this is the roadmap and
  vision example rendered literally, it is the smallest thing that satisfies the stated
  need, it is a two-field control any member understands without documentation, and it
  forecloses nothing — Option B's operator set can be added later without changing the
  model (a map still carries one condition). *Consequence:* four event types sharing one
  output shape need four maps pointing at the same shape, or a default map.
- **Option B — one key path + a small fixed operator set.** Equals, not-equals, exists,
  and **one-of** (value in a list). *Basis:* "one-of" is the single most likely real-world
  need (the four-Stripe-event-types case above) and removes the most common duplication.
  *Consequence:* an operator control in the UI, four behaviours to document and validate,
  and a `not-equals` condition makes overlap between maps far more likely — which raises
  the stakes on Q-08-01(b).
- **Option C — multiple conditions per map, combined with AND (and possibly OR).**
  *Basis:* covers compound cases (`type == "charge" AND livemode == true`).
  *Consequence:* a rule builder, a precedence question inside each map on top of M1's
  precedence question between maps, and it starts to read like the workflow builder the
  vision excludes.
- **Option D — a general expression language (JSONPath / JMESPath / user-written
  expressions).** *Basis:* maximum power. *Consequence:* directly against the vision's
  "no scripting" boundary and its no-code audience; the PM recommends against it and lists
  it only so the Owner can reject it explicitly rather than by omission.

### (b) Key-path notation and reach

- **Option A — dot-notation into nested objects (PM recommendation)**, e.g. `type`,
  `data.object.status`. Object keys only. *Consequence:* a value inside an array (e.g.
  `items[0].sku`) cannot be used to select a map at MVP. The PM's read: acceptable —
  senders put the discriminating field at or near the top level, which is the whole point
  of a discriminator.
- **Option B — dot-notation plus array indexing**, e.g. `items[0].sku`. More reach, more
  notation for the member to learn, more validation.
- **Option C — top-level keys only.** Simplest possible; likely too tight — Stripe's
  discriminator is top-level `type`, but many senders nest theirs one or two levels down.

### (c) Value-match semantics — three small rulings, needed whichever of (a) is chosen

1. **Case sensitivity.** *PM recommendation: **case-sensitive**, exact.* `"CHARGE"` does
   not match `"charge"`. Predictable, and matches how every sender documents its event
   types. Alternative: case-insensitive (kinder, but then two maps differing only by case
   silently collide).
2. **The key is absent from the payload.** *PM recommendation: **the condition does not
   match**, and this is a normal outcome, never an error* — consistent with PRD-08 AC20
   ("unexpected properties never cause an error"). The event then falls through to the
   M1 fallback rule.
3. **Non-string values.** *PM recommendation: **compare the JSON scalar to the configured
   literal by value**, so `true`, `42` and `"42"` behave the way a member reading the
   payload would expect — a number matches a number, a boolean a boolean; a scalar never
   matches an object or array.* Alternative: compare everything as strings (simpler to
   implement, but `42` matching `"42"` is a surprise waiting to happen).

## PM recommendation, in one line
**(a) Option A, (b) Option A, (c) case-sensitive / absent-key-never-matches / typed scalar
comparison** — one dot-notation key path compared for exact equality to one literal value.
It is the roadmap's example rendered literally, it is the only option that keeps the
condition control to two fields, and it is the only option that can be *extended* later
(Option B's `one-of` is a pure addition) rather than walked back. The honest counterweight:
**if the Owner expects members to routinely group several event types onto one output
shape, rule Option B now** — retrofitting an operator into a shipped two-field control
costs more than starting with three fields.

## Impact if unresolved
PRD-08 cannot be approved. AC12 states only that a condition exists, not what it is, so it
is not concretely testable. The Designer cannot design the condition control, and the shape
of that control (two fields, three fields, or a rule builder) drives the whole map-set
management surface. The Principal Engineer cannot specify matching or its validation at
Technical Design. Every other part of PRD-08 is unaffected by any option above.

## Answer
- **Answered By:** **Project Owner**
- **Answered:** **2026-08-26**

**(a) Core expression form — Option A, with a binding extensibility requirement.** One key
path per map at MVP. The Owner's ruling verbatim:

> *"I would like to do one key path but with forward looking to adding additional
> conditions so its not a refactor later. If we set it up right, it should be simple to add
> additional conditions. If its simple, we can add a few in the first go, but i will leave
> that up to the implementor."*

Rendered as **requirements**, not as a note — an untestable "design it well" sentence would
not survive review:

1. **The condition is a structured, named form with an explicit `operator` field present
   from day one**, even while `equals` is the only operator that exists. A bare path/value
   pair with equality *implied* is **not acceptable** — that is precisely the refactor being
   ruled out. → AC12, AC17(a).
2. **A map carries a condition *set***, even when that set holds exactly one condition.
   Adding a second condition later must change **neither** the persisted shape **nor** what
   the API emits. → AC12, AC17(b).
3. **Adding an operator (`one-of`, `not-equals`, `exists`) must be a pure addition** — no
   migration of existing maps, no change to the selection algorithm's contract, no change to
   how a condition is presented on read surfaces. → AC17(c), AC17(d).
4. **Whether any operator beyond `equals` ships in the first pass is delegated to the
   implementor**, conditional on the extensible shape above genuinely making it cheap.
   Recorded as **stated latitude with its bound**, not as an open question — it needs no
   further Owner ruling. Any operator that does ship must be *completely* specified on
   AC12's terms (absent-key behaviour, case, types) and presented in the UI; a half-shipped
   operator is a defect. → AC17(e).
5. **For the record:** `not-equals` materially increases the likelihood of two maps matching
   one event. That is already defined behaviour under Q-08-01(b)'s first-match-wins order
   (AC14) and needs no additional rule.

**Interpretation applied, flagged rather than assumed** *(Owner invited the flag)*:
"additional conditions" is read as covering **both** axes — more **operators** on a
condition, **and** eventually more than **one condition** per map. AC17 is written so the
model forecloses neither. If only one axis was meant, AC17 narrows; nothing else changes.
Combination semantics for a multi-condition map (AND / OR) are **not decided at #8** and are
not built here — the model must simply not preclude them (AC17(f), AC30).

**(b) Key-path notation — Option A: dot notation into nested objects, object keys only.**
`type`, `data.object.status`. **No array indexing** at MVP — `items[0].sku` is not
addressable. → AC12.

**(c) Value-match semantics — all three PM recommendations accepted.** → AC12.
1. **Case-sensitive**, exact: `"CHARGE"` does not match `"charge"`.
2. **An absent key never matches**, and this is a normal outcome, never an error — the event
   falls through to the M1 fallback rules (AC13/AC15).
3. **Typed scalar comparison**: a number matches a number, a boolean a boolean, a string a
   string; `42` does not match `"42"`; a scalar never matches an object or an array.

**Downstream:**
- **AC12** is now concretely testable; its `PENDING M2` tag is removed.
- **AC17 is new**, carrying the extensibility requirement as five checkable clauses plus the
  delegated latitude. It is what a Reviewer holds the shipped model against — the Owner's
  "not a refactor later" is otherwise unverifiable.
- **AC21** picked up the absent-selection-key case so it reads as routine, never a fault.
- **AC30** (no scripting/expressions) now points at the AC12/AC17 condition model as the
  boundary, and names AND/OR combination as not built.
- The Designer has a **three-part** condition control (path + operator + value), with the
  operator shown rather than implied even at one operator.
- **Roadmap M2 is closed.** `docs/status.md` is the Orchestrator's to update.
