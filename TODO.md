# Implementation checklist

## Confirmed decisions

- Reusable open-source plugin; no personal domain/IP/port assumptions in code.
- AGPL-3.0-or-later, admin-only including existing-server management.
- New servers: automatically suggest a free eligible primary allocation; optional override.
- Existing servers: retain an eligible primary; otherwise request confirmation before selecting a replacement.
- Configurable hostname suffix and explicit node-scoped allocation pools.
- Prefer plugin + mc-router only, no custom companion service.
- Target Panel beta38; do not claim wider compatibility without tests.

## Completed first milestone

- [x] Nix shell + runnable dev/test apps, Paseo service and test/lint scripts.
- [x] Disposable pinned Panel and router harness scripts.
- [x] Installable, root-admin-only **read-only prototype**.
- [x] Hostname/allocation/ownership-planning tests and importable archive checks.
- [x] Actual beta38 Livewire preview and wizard hydration/dehydration tests.
- [x] Real disposable mc-router REST API/persistence tests.
- [x] Corrections to AI-written assumptions documented in docs/spike-findings.md.

## Before functional v1

- [ ] Secure no-companion transport decision and deployment documentation.
- [ ] Admin settings UI for suffix, pools and connection configuration (secret storage/redaction).
- [ ] Wizard flow without requiring manual allocation first or hiding a change to core selection.
- [ ] Revalidate allocation at submit and race-test against Pelican's allocation assignment.
- [ ] Durable slug uniqueness/server-UUID ledger and lifecycle capture, respecting Wings failure cleanup.
- [ ] Existing-server actions using Pelican services, explicit reallocation confirmation and authorization.
- [ ] Revisioned queued sync, deletion tombstones, retries and reconciliation; serialized mutation per router.
- [ ] Conservative ownership/conflict semantics including rename and out-of-band edits.
- [ ] Status separation: dry-run, runtime applied, persistence assurance, game-online state.
- [ ] Tests for transport failures, stale jobs, collisions, deletion/rename and foreign route preservation.
- [ ] Decide scoped admin-role permissions (prototype is root-admin-only for safety).
- [x] Validate actual beta38 ZIP import path, including a renamed archive (disposable Panel only).
- [ ] Safe backup/update/uninstall behavior before route-owning releases.
- [ ] Task-scoped permission before any live plugin install or infrastructure change.
- [ ] Disposable live server integration and external Java Minecraft join (not yet authorized).
