---
name: infra-deploy-target-undefined
description: No deploy target/hosting platform is decided yet (Owner decision) — trustProxies() was configured with a placeholder '*' as an interim, not a final infra choice
metadata:
  type: project
---

As of 2026-08-25, `docs/stack/stack.md` still records "Deployment: Not yet defined — Owner
decision" — no Dockerfile/`fly.toml`/Vapor config/deploy job in the repo, CI stops at
test/lint. `bootstrap/app.php`'s `trustProxies(at: '*', ...)` (see
`docs/fixes/ingest-tls-trusted-proxy-config.md`) was chosen specifically because there's no
concrete load-balancer IP range to hard-code yet.

**Why this matters going forward:** once the Project Owner picks a deploy target/hosting
platform, revisit whether `at: '*'` should narrow to an explicit IP/CIDR list (tighter trust
boundary) — this is a deliberate placeholder, not a completed decision, and isn't itself a
security fix for "app directly reachable without the LB in front" (that's an infra/network
guarantee, not enforced by this middleware setting).
