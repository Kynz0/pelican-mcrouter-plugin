# Implementation checklist

## Confirmed decisions

- Reusable open-source plugin; AGPL-3.0-or-later; no personal domain/IP assumptions.
- Admin-only creation and existing-server management, inside the native wizard/edit tab.
- New servers: populated eligible primary selector, automatic selection, optional manual choice.
- Existing servers: retain eligible current primary; otherwise ask for a specific replacement confirmation.
- Configurable suffix, node-scoped allocation pools and router connection.
- Plugin + mc-router preferred, without a custom companion service.
- Pin beta38 compatibility until other versions are tested.
- Make incremental commits; pre-provision/reuse the disposable test admin and preserve its password.

## Completed

- [x] Nix shell/dev/test apps, Paseo service, lint and pinned disposable harnesses.
- [x] Initial read-only prototype, versioned as its own commit.
- [x] Move controls into native creation wizard and admin server-edit tab; remove standalone page.
- [x] Replace optional-override terminology with a populated Primary allocation selector.
- [x] Replace blanket permission toggle with exact-change confirmation modals.
- [x] Native plugin settings UI with visible validation before beta38's swallowed save callback.
- [x] Display alias: show the join hostname wherever the panel renders the primary allocation address (applied on Active in live mode, cleared on removal, custom aliases preserved, never in dry-run).
- [x] Egg gating: settings list of eligible eggs; the step only appears after one is selected (native hooks cannot place it between Egg Configuration and Environment Configuration; it renders as the final step).
- [x] UUID ownership ledger, normalized unique hostname reservations and deletion tombstones.
- [x] Creation capture under core transaction, no plugin DB lock across Wings, failed creation cleanup.
- [x] Existing-server primary changes through core services with feature-limit preservation.
- [x] Native REST client, restricted pinned SSH forwarding, disposable-only loopback HTTP.
- [x] Queued sync/retries, periodic reconciliation, core allocation/node follow, rename and opt-out.
- [x] Separate uncertain write intent from confirmed mapping; recover both sides of lost responses.
- [x] Preserve foreign routes, detect conflicts, quarantine backends until stale ownership is removed.
- [x] Real Panel/Livewire, mocked-Wings lifecycle, actual router, restricted sshd and archive-import tests.
- [x] Actual public browser smoke test; independent test account removed afterward.
- [x] Updated installation, trust, persistence and rollback documentation.

## Before production / stable v1

- [ ] Add remaining Tabler icons for closer panel look & feel (creation step badge, action buttons, settings fields, allocation select icons, notification icons).
- [ ] Review whether trusted Panel-wide API access via SSH is acceptable (no native namespace authorization).
- [ ] Task-scoped authorization for a live install and any SSH/systemd/permission/DNS/NAT changes.
- [ ] Validate live router restart durability, writable config/sandbox, backups and restore procedure.
- [ ] Verify queue workers/scheduler and contention/races under the target live deployment.
- [ ] SQLite is exercised; test other databases and multi-host/shared lock configurations before claiming support.
- [ ] Review unsupported complex router discovery/scaling/proxy configuration interactions.
- [ ] Disposable actual Wings/game creation, failure cleanup, port rebind behavior and external Java join.
- [ ] Production update/uninstall validation with matched snapshots and worker restarts.
- [ ] Decide scoped delegated-admin permissions (alpha is root-admin-only).
- [ ] Optional upstream hook for cleaner first-step placement/filtering of the native primary field.
- [ ] No adoption of manual routes, multi-router support, Bedrock or voice-port automation yet.
