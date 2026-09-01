# Q-18-02: AC23 cannot be delivered as written for a token that travels in a URL

- **Feature:** destination validation (item #18)
- **Requested By:** Principal Engineer (arising from `docs/questions/prd-18-q-18-01-validation-enforcement-and-send-safety.md` item 4)
- **Directed To:** **Product Manager**
- **Required By:** Before the confirmation page is built. **Non-blocking for the rest of `plan-18`** —
  the answer changes one screen and nothing else.
- **Priority:** Medium. The security property that matters, AC24, is unaffected either way.
- **Status:** **CLOSED — Project Owner ruled option 1, 2026-08-31.** AC23 is narrowed to the layers
  this application controls and PRD-18 AC23 is amended in place to say so. The signed-URL shape
  stands; the confirmation page stays plain and needs no JavaScript to approve.

  **Owner's reasoning, recorded because it is the part worth reusing:** the risk is bounded by the
  threat model rather than by the token being hashed. The link is HTTPS in transit; the recipient's
  infrastructure logging it is the feature working, since the recipient is the party being asked to
  approve; and anyone able to read this application's access logs already has production access and
  could set the column directly. Single-use and the 7-day expiry bound what a recovered link is worth.

  **One correction recorded so the reasoning is not reused wrongly:** a Laravel signed URL is not
  encrypted, and its `signature` parameter is an HMAC proving the URL was not *tampered with*. It
  gives no protection against replay — whoever holds the complete URL resends it verbatim and never
  needs to reverse anything. A bearer token in a URL is safe here because of the threat model above,
  not because it is signed.

## The finding

AC23 requires the validation link and its token to appear in **no** application log, delivery record,
analytics record, error report or support output. The application-layer half of that is deliverable
and `plan-18` carries it.

The rest is not deliverable for a token carried in a URL, and no framework feature changes it. A URL
is recorded by the web server access log, by anything sitting in front of the application, and by the
recipient's own infrastructure the moment the challenge arrives — that last layer being one this
project does not operate and cannot constrain. Laravel's signed URLs carry the signature in the query
string and Fortify's password reset carries its token in the path; both are logged wherever URLs are
logged. That is the nature of a bearer token in a URL, not a defect in either feature.

## The one shape that would satisfy AC23 literally

Carry the destination identifier in the URL and the secret in the URL **fragment** — the portion after
`#`, which browsers never transmit to any server. The confirmation page reads the fragment in
JavaScript and submits it in the POST body. Nothing that logs URLs ever sees the secret, including the
recipient's own infrastructure.

The cost is a hard JavaScript dependency on the confirmation page. That page is the only screen in the
product seen by somebody with no account, arriving cold from a link, possibly in an unusual client. If
JavaScript does not run, approval is impossible and there is no fallback that preserves the property.

## What is being asked

Choose one, and the criterion is amended to match rather than left contradicted:

1. **Narrow AC23 to the application layer** — the token appears in no log, record or output this
   application controls. Accept that access logs and the recipient's infrastructure see the URL, with
   the risk bounded by the token being single-use and 7-day-limited. The confirmation page stays plain
   and works without JavaScript. *(Principal Engineer's recommendation: AC24 is the property that makes
   the feature prove anything, and it is untouched by this choice.)*
2. **Keep AC23 as written and adopt the fragment shape**, accepting that approval requires JavaScript.

## What is not being asked

- **Whether AC24 holds.** It does, under either option, and it is unaffected by where the token travels.
- **Whether the token stays single-use, unguessable and 7-day-limited.** It does; AC22 is untouched.
