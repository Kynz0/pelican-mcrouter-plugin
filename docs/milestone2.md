# Milestone 2 — integrated admin UI and functional local routing

Version **0.2.0-dev** is an experimental alpha targeting Panel beta38 and
mc-router v1.47.1. It is not approved for production installation.

## Implemented

- MC Router step **inside the existing creation wizard**, before Information.
  Toggle defaults off. Enabling selects an eligible node and a free primary
  automatically; the Primary allocation dropdown contains eligible choices.
  Its choice is mirrored into Information. Eligible changes in the native
  selector are mirrored back. Ineligible/mismatched final choices are rejected,
  not silently overridden. Disabling restores the previous normal selection.
- MC Router tab **inside the existing admin server-edit page**. No separate
  routing navigation page and no client console/files controls.
- Existing eligible primaries are retained. An ineligible primary has an
  **Automatically select eligible replacement** action with a specific modal.
  A manual replacement likewise needs confirmation for that exact allocation.
  **Save MC Router settings** saves routing separately from the core server Save.
- Admin → Plugins → MC Router → Settings provides suffix, explicit allocation
  pools per node, and transport configuration. Settings validation runs in the
  schema as well as the service because beta38's Plugin model catches exceptions
  from the save callback. Invalid settings remain visible instead of disappearing.
- Dry-run is the default. It persists plugin choices/hostname reservations but
  never changes existing primaries or writes router routes. A proposed port is
  not reserved; availability is rechecked on a real save.
- Queued apply/remove, pending/active/error/disabled/dry-run status, case-normalized
  hostname reservations, rename cleanup, opt-out, deletion tombstones, retries
  and once-per-minute scheduled reconciliation.
- Ownership is recorded by immutable server UUID, with separate confirmed and
  uncertain attempted backends. Lost POST responses can be retried without
  forgetting the previous mapping. Foreign routes are never adopted merely
  because their hostname or backend matches.
- Backend ports still claimed by unremoved/uncertain routes are quarantined from
  reuse. The selectors omit them, and model creation/primary-change guards also
  prevent reuse until reconciliation finishes, even for normal non-routing
  creation. This narrow safety exception prevents an old hostname from joining a
  newly assigned server while deletion cleanup is pending.
- Existing-server port changes use Pelican's BuildModificationService, preserve
  feature limits and retain the old primary as an additional allocation.
  The plugin does not automatically restart games.

## Creation lifecycle

A container decorator delegates to Pelican's original ServerCreationService;
no service/page implementation is copied. A request-local capture uses model
callbacks within **Pelican's own** transaction to validate/lock the primary and
reserve the desired hostname. The route is marked unprovisioned until the
original service completes its Wings call. No router job runs before that.
Failed creation releases unapplied claims after core cleanup. No plugin database
transaction spans a Wings or router network call.

The native wizard-name update is observed using the pinned Livewire PHP update
hook, so suggestions follow normal state submissions until the slug is edited.
The native name field is not live-on-keystroke. Custom step/tab components clear
cached child schemas when attached to a new Livewire container, avoiding stale
state from Panel's static registration hooks. These integrations are version
specific, not a promise of future beta compatibility.

## Secure transport without a companion service

The SSH mode uses **OpenSSH `ssh -W`** to forward HTTP directly to mc-router's
loopback API. It does not open a remote shell, execute remote commands, write
remote files, grant root SSH, or run a persistent tunnel/companion service.
Strict pinned host-key verification, a dedicated key/account, explicit timeouts,
bounded responses and no inherited SSH user configuration are enforced.

**Trust limitation:** `PermitOpen` restricts the endpoint, not HTTP methods or
hostname namespaces. The native API has no per-route authorization. A compromised
Panel/key could change *all* mappings through that API. Plugin validation and
ownership protections defend normal operation, not a compromised Panel process.
Deploy only if that trust model is acceptable; independent namespace enforcement
would require upstream API support or an authenticated restricting gateway.

Local HTTP mode is loopback-only and permitted only in local/testing application
environments. Public demo mode forces dry-run regardless of submitted settings.

## Verified evidence

These commands passed against disposable pinned checkouts:

```sh
nix run .#test
nix develop -c php tests/panel.php
nix develop -c php tests/creation.php
nix develop -c python3 tests/router_api.py
nix develop -c python3 tests/sync_driver.py
nix develop -c python3 tests/ssh_driver.py
```

- 42 pure hostname/allocation/ownership checks and 11 HTTP framing checks.
- Actual native Livewire wizard/tab state, populated primary selection, core
  selector mirroring, toggle-off restoration, specific confirmation dialogs,
  edit-tab save, settings slide-over save/validation and non-admin rejection.
- 26 checks using real Panel creation/build services with mocked Wings: disabled
  flow, successful capture, duplicate rollback, network-boundary transaction
  depth, failed Wings cleanup/slug reuse and confirmed primary replacement.
- 45 checks against a real temporary mc-router: apply/readback, rename, allocation
  follow, outage recovery, drift, uncertain retries, foreign conflicts, opt-out,
  re-enable, uniqueness, deletion, quarantined backend reuse, guarded migration rollback and unrelated routes.
- A real unprivileged temporary sshd and router verify `ssh -W` apply/read/delete,
  large/chunked responses, host-key rejection, remote command denial and rejection
  of non-allowlisted forward destinations. No live SSH configuration was changed.
- Actual beta38 ZIP import, including a renamed archive.
- A real Chromium/Playwright smoke test against the public demo verified login,
  creation controls, the native MC Router edit tab and plugin settings. Its
  separate synthetic account was removed; the user's preview account/password
  was preserved. The browser uses pinned Playwright 1.63.0.

## Still not verified / release gates

- No live Panel/Wings/router integration, DNS/NAT edits or player join.
- No real game restart or proof that a particular egg rebinds immediately after
  a primary change. Operators may need a separately approved restart.
- SQLite integration is exercised; other databases/multiple Panel hosts need
  testing and a shared reliable lock backend. Mutations serialize per router;
  unrelated external writers cannot be made race-free with the native API.
- The real API persistence-failure test proves that runtime readback does not
  establish disk durability. **Active means runtime mapping accepted only.**
- API serialization supports the router's simple static mapping schema. Complex
  discovery/proxy/scaling configurations need separate compatibility testing.
- Production transport, queue/scheduler operation, file permissions and router
  restart persistence require a reviewed deployment plan and scoped approval.
- Delegated admin permissions, route adoption/migration, multi-router management,
  Bedrock and voice/extra-port automation are not implemented.
