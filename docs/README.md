# Documentation map

Current implementation: [prototype instructions](prototype.md), [verified spike findings](spike-findings.md), and [implementation checklist](../TODO.md). These supersede conflicting assumptions in the original AI-written planning documents below.

- [Product brief](product-brief.md): user-approved v1 behavior, scope, UX, constraints.
- [Live topology](topology.md): observed Panel/Wings/router/DNS state and boundaries; recheck before implementation.
- [Pelican integration research](pelican-integration.md): installed beta38 wizard, allocation model, plugin hooks, implementation caveat.
- [Architecture proposal](architecture.md): data model, state transitions, router sync, trust boundary, failure behavior.
- [Verification plan](verification.md): tests and acceptance criteria, including a disposable server and external status check.
- [Agent handoff](agent-handoff.md): order of work, unresolved questions, development conventions and source links.

**Status:** local read-only plugin prototype and disposable compatibility harness implemented with user approval. No production changes. The original topology observations remain unverified live; current upstream-source and local test evidence is recorded in the spike findings.
