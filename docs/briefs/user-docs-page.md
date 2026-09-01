# Brief: user documentation page

## What

There is no user-facing documentation. A new user who registers sees a
dashboard and has to infer what a proxy is, how the ingest URL works, why a
destination is not receiving anything, and what signing headers a receiver
should verify. This brief adds one public documentation page, linked from the
landing page, that reads start to finish as a guided walkthrough and also lets
a returning user jump straight to the section for the feature they are using.

## Why

Everything the page documents already ships. The gap is explanatory, not
functional, so the cheapest correct fix is static content next to the product
rather than an external documentation site, a content system, or a Markdown
pipeline.

## Decisions

- **One page, one route.** `Route::inertia('/docs', 'Docs')->name('docs')`,
  public and unauthenticated (registered outside the `{current_team}` prefix
  and outside the `auth` group). Documentation must be readable before signing
  up, and a signed-in user reads exactly the same page.
- **Static Vue, no content pipeline.** The prose lives in the template of
  `resources/js/pages/Docs.vue`. No Markdown renderer, no CMS, no new
  dependency. Editing docs means editing the component.
- **In-page navigation, not sub-routes.** A sticky table of contents on the
  left at `lg` and above, collapsing to a plain list above the content on
  small screens. Each section is an `<h2>` with a stable `id`; the contents
  list is anchor links to those ids, so `/docs#signing` is linkable and the
  browser handles the scrolling. No JavaScript scroll-spy, no active-section
  highlighting — the page is static UI by intent.
- **Section order follows the actual first-run path**, so a top-to-bottom read
  is a working walkthrough: quick start, proxies, destinations, sending
  webhooks, signing, retries and failure, events and replay, teams, account
  security, troubleshooting.
- **Signing examples are tabbed by language.** Node and PHP, in a `reka-ui`
  `Tabs` group used directly on the page rather than through a new
  `components/ui/tabs` wrapper set — one page uses it, and the primitive
  already carries the roles and keyboard behaviour a hand-rolled tab strip
  would have to reimplement.
- **Code blocks are highlighted with Shiki**, through
  `resources/js/components/CodeBlock.vue`: an IDE-style frame with a title bar,
  line numbers, and GitHub's light and dark themes following the app's theme
  toggle. Every part of it is dynamically imported and the grammars load per
  language on first use, so the documentation page's first paint carries none
  of it and the PHP grammar is fetched only when a reader opens that tab. The
  header sample renders as plain text rather than pulling in the `http`
  grammar, which is an order of magnitude larger than the rest combined. Blocks
  render as unhighlighted text until Shiki resolves, so the code is readable
  without the highlighter.
- **Examples are concrete and copyable.** A `curl` ingest example, a signing
  verification sketch, and the exact header names the service sends. Values
  that come from configuration (retry defaults, retention window) are stated
  as the shipped defaults, not as immutable guarantees.
- **Landing page links to it** from the header nav (next to Log in / Register,
  and visible to signed-in visitors too) and from a text link under the hero
  buttons. No other entry points in this brief — in-app links from the sidebar
  are a separate, later change.
- **Accuracy over completeness.** Every claim on the page is checked against
  the code that implements it. Anything not verifiable is left out rather than
  described approximately.

## Content the page must state correctly

- Ingest endpoint is `POST` or `PUT` to `{ingest URL}/ingest/{token}`; the
  token is the only credential; an unknown token is a 404.
- The acknowledgement returned to the sender is the proxy's configured response
  status and body, sent immediately, independent of delivery outcome.
- Inbound headers are forwarded to every destination, except the hop-by-hop set
  and `Host`/`Content-Length`, which are dropped as protocol-breaking.
- A destination receives nothing until it is approved: the Validate action
  sends a challenge request to the destination URL containing a signed approval
  link, and whoever runs that endpoint opens it and approves.
- Simple versus Enhanced: Enhanced stores the dispatched payload and unlocks
  configurable retries; Simple retries on the system default (5 attempts).
- Signing headers are `WebhookProxy-Id`, `WebhookProxy-Timestamp` and
  `WebhookProxy-Signature`; the signature format is `v1,<base64 HMAC>`, one
  entry per live secret during a rotation overlap.
- Sensitive field names are masked wherever payloads are shown, JSON payloads
  only.
- Payload retention is 30 days by default; a cleaned payload cannot be
  replayed.

## Tasks

1. `resources/js/pages/Docs.vue` — page, contents list, sections, examples.
2. `routes/web.php` — the public `/docs` route.
3. `resources/js/pages/Welcome.vue` — header nav link and hero text link.
4. `tests/Feature/DocsTest.php` — the route renders the `Docs` component for a
   guest and for an authenticated user.
5. Build assets on the host, then a browser pass over the page at desktop and
   mobile widths, both colour themes.

## Done when

- `/docs` renders for a guest and for a signed-in user.
- Every section is reachable from the contents list, and every anchor resolves.
- The landing page links to it.
- `composer lint`, `composer types:check`, `pnpm lint:check`,
  `pnpm types:check` and the PHP suite all pass.
- The page has been read in a browser at mobile and desktop widths in light and
  dark themes.
