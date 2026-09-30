# Documentation map

Current implementation: [alpha instructions](prototype.md), [milestone 2 findings](milestone2.md), [next-session live handoff](handoff-live.md), [original spike findings](spike-findings.md), and [implementation checklist](../TODO.md). These supersede conflicting assumptions in the original AI-written planning documents below.

- [Product brief](product-brief.md): user-approved v1 behavior, scope, UX, constraints.
- [Live topology](topology.md): observed Panel/Wings/router/DNS state and boundaries; recheck before implementation.
- [Pelican integration research](pelican-integration.md): installed beta38 wizard, allocation model, plugin hooks, implementation caveat.
- [Architecture proposal](architecture.md): data model, state transitions, router sync, trust boundary, failure behavior.
- [Verification plan](verification.md): tests and acceptance criteria, including a disposable server and external status check.
- [Agent handoff](agent-handoff.md): historical plan; superseded by [handoff-live.md](handoff-live.md).
- [Live handoff](handoff-live.md): **start here for the next session** — current state, plugin map, live-deployment checklist, and session gotchas.

**Status:** 0.2.0-dev alpha with integrated admin wizard/tab, plugin settings, durable route synchronization and disposable compatibility/lifecycle/router/SSH/browser tests. The public preview is forced dry-run. No production changes. Original topology remains unverified live; milestone 2 records current implementation evidence and remaining gates.
