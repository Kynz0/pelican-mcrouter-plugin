# Coding agent handoff

**Historical plan:** the initial read-only prototype is now implemented. Read [prototype.md](prototype.md), [spike-findings.md](spike-findings.md), and [../TODO.md](../TODO.md) first. They supersede the old helper requirement, wizard-hook placement, namespace assumption, and transaction guidance below. The user has approved local development, AGPL-3.0-or-later, auto-allocation with override, and admin-only existing-server management, not production changes.

The original handoff started from a planning workspace with **no plugin code yet**. Follow this order; stop and ask the user (by ending your turn with a short status) before anything that touches production. Core Pelican and mc-router are deployed and must not be modified casually.

## Phase 1 — workspace & docs (do first)

1. Read every file in this repo: `AGENTS.md`, `README.md`, then `docs/*` in map order.
2. Security check: ensure the workspace contains **no secrets** (tokens, APP_KEY, `.env` values, WAN IP is allowed as operational fact but flagged; see `topology.md`). The environment's `Tirith` scan already approved the docs scaffold; don't introduce secrets or encoded blobs.
3. Validate the docs skeleton: `nix develop -c python3 scripts/check_docs.py` (see `verification.md` §0). Fix any broken relative links before coding.
4. Start the Paseo `docs` service only if you need a preview; don't claim persistence.
5. Add repo issues or a TODO file for each open question below; keep docs updated as facts change.

## Phase 2 — plugin spike (local, staging)

1. Get a disposable Pelican Panel checkout matching `v1.0.0-beta38` via git tag; set up a dev harness (SQLite, filesystem cache; dev server via Nix devShell when possible).
2. Generate plugin skeleton: `php artisan p:plugin:make` (see options from installed Panel: name, author `kynzo`, category `plugin`, panels `admin`, description; sensible plugin id; `namespace` is `App\Plugins\...`-style; inspect generated `plugin.json`).
3. Implement the extra `CreateServer` wizard step via `App\Traits\Filament\CanCustomizeSteps` (`registerCustomSteps(StepPosition::After|Before, ...)`). Fields: toggle (default off), suggested slug (auto-format from name, stop auto-follow after edit), preview text, primary-allocation select filtered to the plugin-approved router pool for the selected node; disabled when toggle off; hidden when the node has no eligible pool. Live state must not silently override a normal-flow allocation choice. Note: in this release, **`älle`** form fields must also survive Livewire hydration; verify the extra field values reach `handleRecordCreation(array $data)`; persist plugin data in your own DB tables via the plugin migration (server UUID joins, not FK name).
4. Validation on submit: slug uniqueness (case-insensitive DB constraint), reserved hostname denylist, node eligibility, port-in-pool, allocation free. On success transaction: create Pelican server normally and persist desired state (same transaction), route status `Pending`; no overlapping long-held transaction across network calls (SQLite).
5. Queue/jobs and reconciler: queued job applies route via a narrow, authenticated helper seam; reconciliation loop repairs drift/races and cleans routes of deleted servers. Keep `swan`-like non-plugin entries and foreign routes intact.
6. Listen/create events for server delete/mutation if available in beta38 plugins; otherwise reconcile-only is acceptable (record decision here).

## Phase 3 — router helper (staging only initially)

- Choose a transport with narrow allowlist semantics (see `architecture.md`). Unit-test its JSON route update, wildcard-suffix validation, backend IP/port allowlist, atomic rename, snapshot, readback. **Never write live `routes.json` until the user explicitly approves.** Test against a disposable routes file with a test router or offline dry-run mode.
- Estimate test coverage: given node allocation ranges fetched live (do not hardcode), verify a correct mapping reaches a real (staging) mc-router instance and is confirmed via logs/readback.

## Phase 4 — go-live & handover

- Re-run all tests; walk the user through an installation plan (with snapshots/pre-approval) before touching live DNS (the temporary `swan` DNS record, wildcard creation) or stopping any game.
- After live integration approve & execute one-at-a-time: add per-node pool config, wildcard DNS creation, router-file apply, disposable server test, external probes.
- Update `topology.md` with any changed facts, then deliver a short report (what changed, what verified, what's left).

## Conventions

- Nix flake is the default dev environment (`nix develop`; `apps.test` runs `scripts/check_docs.py`; later add PHP/composer/dev-tooling as needed to the same shell). Nix flakes require the file tracked in git for `nix develop` in a dirty tree (`git add -N flake.nix`).
- Currency: check **upstream** docs only at the pinned versions above; beta compatibility is volatile. If the spike disproves any docs statement, patch this workspace's docs and re-commit.
- Use the paseshare preview only for the docs site; the coding agent may set up its own dev server against a live Paseo service port if it needs a real Laravel app running.
- After the spike, your target deliverable is a clean branch (not main) with tested plugin skeleton, docs updated, and a short findings report. **No production changes.**

## Facts to recheck early (state changed since writing)

- The user added the forwarded extra-port ranges **after** these docs were drafted; re-read live allocations before finalizing pool config (docs reference them but implementation must read the live table). All allocations/nodes/route mappings were observed 2026-09-30 and remain subject to change.
- DNS wildcard `*.mc.kynzo.org` was **not** created when last checked; it requires user-authorized Cloudflare change. Do not assume it exists; verify externally.
- Migration limit / security approval for the router helper is not yet granted; coordinate with the user per step. The existing `swan` route and endpoint is a **legacy manual route** and stays until explicitly decided.
