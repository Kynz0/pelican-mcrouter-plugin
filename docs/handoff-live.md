# Next-session handoff — making it actually work with mc-router

Read this together with [milestone2.md](milestone2.md) (what is built and
verified), [prototype.md](prototype.md) (user-facing install/usage), and
[spike-findings.md](spike-findings.md) (native API facts). This file is the
operational bridge to the next session: everything known that matters for a
live integration, in one place.

## Current state (branch `feat/pelican-mc-router-plugin`)

- Version `0.2.0-dev`, AGPL-3.0-or-later, admin-only, Panel `v1.0.0-beta38`,
  mc-router `v1.47.1` (both pinned in the harness). All work is committed.
- Fully working locally: admin UI (wizard step + edit tab + settings), egg
  feature gating, dry-run mode, route lifecycle against a **real disposable
  mc-router** (local HTTP and restricted SSH transports), ZIP import, browser
  smoke test.
- **Nothing is installed on the user's live Panel/router.** The live panel is
  `/var/www/pelican` (never touch without explicit task-scoped approval), the
  live router CT is `192.168.1.204`.

## User-approved requirements recap

- Admin-only; creation step + edit tab; no client-area controls.
- Auto-populated primary allocation from explicit per-node allocation pools
  (settings hold **allocation IDs**, not ports); replacement of an ineligible
  existing primary requires an explicit confirmation.
- Hostname suffix configurable; wildcard DNS and TCP 25565 forwarding stay
  outside the plugin.
- Plugin + mc-router only (no companion service) via restricted SSH forwarding
  to the loopback API — accepted trade-off: the API has no authorization, so
  the Panel/key must be trusted for **all** routes (documented).
- Egg eligibility via the `mc-router` **egg feature**; fail-closed (no feature
  → no routing anywhere). Settings list syncs the feature; manual feature
  edits in the egg editor also work.
- Dry-run is default; live mode requires non-preview config.

## Plugin map (where things live)

| File | Role |
| --- | --- |
| `plugin/mc-router/plugin.json` | Manifest; `panel_version: 1.0.0-beta38`; bump version each release. |
| `src/McRouterPlugin.php` | Registers wizard step (`StepPosition::After`) and edit tab (`TabPosition::After`), both gated; implements `HasPluginSettings`. |
| `src/Providers/McRouterProvider.php` | All hooks: `Allocation` quarantine scope + saving guard, `Server` creating/created/updating/updating/deleted hooks, Livewire `update` hook (name→slug suggestion, allocation mirroring) and `call` hook (edit-tab save via page Save), `ServerCreationService` decorator, scheduled reconciliation. |
| `src/Filament/RoutingFields.php` | The form fields for step/tab; `eggEligible()`; `allocations()` (quarantine filter); `autoAllocation()`; preview state helpers. |
| `src/Filament/RoutingStep.php`, `RoutingTab.php`, `FreshSchema.php` | Custom step/tab that clear cached child schemas (see gotchas). |
| `src/Filament/SettingsForm.php` | Settings fields incl. suffix rule (validates whole settings, works around beta38 swallowing exceptions), minecraft-tag helper button, test-connection action. |
| `src/Services/Settings.php` | Settings store (`mc_router_settings` row, JSON) + validation + connection-change guard. |
| `src/Services/RouteManager.php` | `save()` (edit path), `saveFromForm()` (called from the Livewire `call` hook), `hostname()`, `allocation()` validation, `reserve()`, `guardBackend()`, `queue()`. |
| `src/Services/RoutedCreationService.php` | Decorator around core `ServerCreationService`; strips `mc_router_*` from data, egg-feature gate, capture context, marks provisioned, queues sync. |
| `src/Services/CreationCapture.php` | Model `creating`/`created` hooks inside Pelican's own creation transaction; validates/locks primary, reserves hostname, creates record. |
| `src/Services/Synchronizer.php` | The heart: apply/remove/rename/readback, ownership conflicts, uncertain `attempted` map, drift healing, deletion cleanup, `ip_alias` display alias. |
| `src/Services/RouterClient.php` | Local HTTP (local/testing only) or `ssh -W` transport; strict ssh options; bounded responses. |
| `src/Transport/HttpResponse.php` | Minimal HTTP parser for the SSH-forwarded raw response (chunked, content-length, size caps). |
| `src/Models/RouteRecord.php`, `BackendQuarantine.php` | Ledger (`mc_router_routes`, `mc_router_hostnames`) and the global Allocation scope hiding claimed backends. |
| `src/Jobs/SyncRoute.php`, `src/Console/Commands/Reconcile.php` | Queued sync (tries 3, backoff) + `mc-router:reconcile` (scheduled every minute). |
| `database/migrations/...` | `mc_router_settings`, `mc_router_routes` (applied/attempted JSON, revision, status), `mc_router_hostnames` (unique reservation); rollback guards refuse live claims. |

## Data model / invariants to preserve

- One `mc_router_routes` row per server (**immutable `server_uuid`**), with
  `applied` (confirmed backends) and `attempted` (uncertain writes) JSON maps.
- `mc_router_hostnames` = unique case-... (lowercased) hostname reservations;
  names stay reserved until reconciliation removes them (rename keeps old name
  reserved until the new mapping is confirmed).
- Quarantine: unassigned allocations whose backend appears in any route's
  `applied`/`attempted` are excluded everywhere (scope + guards) until cleanup —
  prevents an old hostname from joining a newly assigned server.
- Display alias: on Active in live mode, primary allocation `ip_alias` = join
  hostname; cleared on removal; custom pre-existing aliases preserved; never in
  dry-run.
- Sync holds **no DB transaction** across network calls; writes are serialized
  by `Cache::lock('mc-router:mutation', 180)` — needs a shared, reliable cache
  backend if the Panel runs on multiple hosts.

## Live-deployment checklist for the next session (in order)

1. **mc-router CT access (needs user approval, uses `mc-router-ops` skill):**
   create a dedicated unprivileged SSH account (e.g. `mcrouter-api`) with a
   dedicated key; sshd config `PermitOpen 127.0.0.1:<api-port>`,
   `AllowTcpForwarding local`, `PermitTTY no`, `ForceCommand /bin/false` —
   mirror `tests/ssh_driver.py` which already proves this exact setup works.
   Generate/pin `known_hosts` on the Panel host; store the private key on the
   Panel host (path only in settings).
2. **Verify API persistence on the live router:** the recorded routes file is
   root-owned while the service runs unprivileged; `SaveRoutes` (triggered by
   API writes) may fail silently (POST still returns 201 — proven in
   `tests/router_api.py`). Check ownership/permissions of
   `/etc/mc-router/routes.json` and the service user; if needed chown to the
   service user or make the file writable, **with user approval and a backup**.
   Also confirm the routes file schema matches the simple static mappings the
   API rewrites (custom discovery/proxy config may be incompatible — see
   spike findings).
3. **Enable the API port on the router** (`-api-binding 127.0.0.1:<port>` or
   env `API_BINDING` in its systemd unit) — loopback only. Requires a service
   edit/restart: approval + backup.
4. **Panel-side settings** (Admin → Plugins → MC Router → Settings): real
   suffix (e.g. `mc.kynzo.org`), real allocation-ID pools per node, SSH
   host/port/user/api-port/key-path/known-hosts-path; use **Test saved router
   connection**; then save. Remember: connection/suffix changes are blocked
   while confirmed/uncertain live routes exist.
5. **Panel queue workers + scheduler must run** (SyncRoute is queued;
   reconciliation is scheduled every minute; else `php artisan mc-router:reconcile`
   manually). Check `QUEUE_CONNECTION` on the live panel and that the schedule
   cron exists.
6. **DNS** (needs approval): one DNS-only wildcard `*.mc.<domain>` A record to
   the current WAN IP; verify externally; note WAN-IP drift handling is a
   separate operational concern (not plugin v1).
7. **Install on live Panel (needs approval + backup):** upload ZIP via
   Admin → Plugins → Import, or manual `plugins/mc-router` + `php artisan
   p:plugin:install mc-router`. Migration `..._000001` creates tables; the
   settings row is created on first save. Restart queue workers after install.
   Keep a restore path (DB dump + `plugins/` copy + router config snapshot).
8. **First live route:** disposable test server + external verification per
   `verification.md` §4 (LAN handshake probe, check-host.net, mcsrvstat,
   missing-backend must NOT fall through, player join is the human gate).
   One route at a time; keep `swan` (legacy manual route) untouched.

## Gotchas that already cost time (do not rediscover)

- **beta38 `CanCustomizeSteps`** only supports before-all/after-all steps; no
  mid-wizard insertion and no fields inside Information. Egg gating solves the
  "only Minecraft eggs" requirement with the step placed last.
- **Static step/tab instances** are shared across Livewire requests: custom
  `RoutingStep`/`RoutingTab` with `FreshSchema::container()` clear cached child
  schemas; without it, stale state from other requests leaks.
- **beta38's `Plugin` model swallows exceptions** from `saveSettings` — hence
  the schema-level `->rule(...)` on the suffix field so validation errors are
  visible in the slide-over.
- **Livewire pinned hooks** used by the plugin (version-specific, re-verify on
  Panel updates): `Livewire::listen('update', ...)` for name/egg/allocation
  mirroring and `listen('call', ...)` for edit-tab save-on-page-save. The
  `call` hook's `$finish` closure only runs when the method **succeeds**.
- **Toggle in forms has no `prefixIcon`** (schema Toggle ≠ support toggle);
  TextEntry uses `->icon()`, not `->prefixIcon()`.
- **`Placeholder` lives in `Filament\Forms\Components`**, entries in
  `Filament\Infolists\Components` (usable inside form schemas).
- The global `BackendQuarantine` scope hides claimed allocations from **every**
  Allocation query — internal guards must use `withoutGlobalScopes()` (see
  `CreationCapture::creating`).
- **`php -S` + opcache**: after editing plugin files, restart the Paseo `dev`
  service or the served app may serve stale classes while CLI tests look fresh.
- The dev server reads `.dev/public-url` per request for asset/signed-link
  origins (quick-tunnel URL changes between sessions).
- **Livewire testing assertions that fail outside phpunit** crash with a
  cryptic `Registry.php assert(Configuration)` (the failure *reporter* is
  broken, not the assertion). Use the trace-catch trick or check state
  manually.
- Filament's mounted-action schemas in tests: use
  `$page->instance()->{'mountedActionSchema0'}` +
  `getFlatComponents(...)` to find nested action keys; `callAction` with a
  `TestAction::schemaComponent($key, schema: '')`.
- `fillForm()` did not update mounted-action data in this context; use
  `set('mountedActions.0.data.<key>', ...)`.

## Test suite (all green; keep green)

```sh
nix run .#test                                 # syntax, docs, domain, transport, archive checks
nix develop -c php tests/panel.php             # native UI + settings + gating (needs setup-panel.sh)
nix develop -c php tests/creation.php          # creation lifecycle, mocked Wings
nix develop -c python3 tests/router_api.py     # raw API facts
nix develop -c python3 tests/sync_driver.py    # 52-check real-router lifecycle
nix develop -c python3 tests/ssh_driver.py     # real sshd, restricted -W transport
nix develop -c bash scripts/integration.sh     # package + import + above
nix develop .#browser -c bash scripts/browser-smoke.sh   # Chromium smoke (public preview)
```

Harness notes: `scripts/setup-panel.sh` (re)installs the plugin into
`.dev/panel` — run it after changing plugin.json/migrations; plain `cp -R
plugin/mc-router/src/. .dev/panel/plugins/mc-router/src/` suffices for code
edits. `scripts/setup-preview.php` resets demo settings + creates the Demo
Minecraft egg (feature enabled) + sample servers; `--reset-access` prints a
fresh password-setup link; existing admin password preserved otherwise.
Lint/docs/check: `nix run .#test`. Packaging: version comes from
`plugin.json`; run `scripts/package.py` before `tests/import.php`.

## Open items before a live install (from TODO.md, condensed)

- Approval + plan for live: plugin install, SSH account/key on the router CT,
  API binding + file ownership, DNS wildcard, queue/scheduler verification.
- First live verification: disposable server, external join (human gate).
- Decide/review the trust model (Panel compromise = all routes compromised).
- Not yet supported: delegated-admin permissions, multi-router, Bedrock,
  voice/extra ports, route adoption/migration, upstream PR for true mid-wizard
  step placement (user may want it; draft PR is an option).
- Code style: Pint/PHPStan not yet run on the plugin; consider adding before
  tagging a stable release.

## Session conventions (from AGENTS.md + this session)

- Commits as `kynzo <kynzo@users.noreply.git.kynzo.org>`; commit incrementally.
- Preview: `paseo script start dev --cwd "$PWD"`, then
  `paseshare <port> --detach`; write the origin to `.dev/public-url`; run
  `php scripts/setup-preview.php` (preserves the test admin password;
  `--reset-access` for a fresh setup link). Test admin: `preview@example.test`.
  Demo is forced dry-run via `MC_ROUTER_PREVIEW_ONLY=true` in `.dev/panel/.env`.
- Never commit credentials; never touch live services without explicit
  task-scoped approval; disposable harness lives under ignored `.dev/`.
