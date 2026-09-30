# Trying the development alpha

**Version 0.2.0-dev** adds native admin controls, settings and locally tested route
synchronization. It is not production-ready. The public demo is forced into
**dry-run**, uses fake documentation-range nodes and cannot reach real games.

## Public demo

**Latest URL: https://tail-ross-nutrition-territory.trycloudflare.com**
(temporary quick-tunnel; a new URL will be posted if it changes)
`preview@example.test` (username `preview`). Your chosen password is preserved.
A fresh preview should have a test admin and sample data ready automatically;
credentials are never committed or copied from production. If access needs
resetting, provide a private password-setup link, not a weak public admin password.

Try:

1. **Admin → Servers → Create → MC Router**: enable Use mc-router. The Primary
   allocation is already selected; choose another eligible one if desired.
   Continue to Information to enter the normal server name; its next state update
   suggests the slug unless you edited it. The selection is mirrored between
   steps. Do not submit expecting a game server: the demo has no real Wings.
2. **Admin → Servers → Edit → MC Router**: enable routing on Eligible existing
   server to keep its port. On Ineligible existing server, approve automatic
   replacement in the modal. Save with **Save MC Router settings** inside the tab.
   Dry-run stores the proposal without changing the server port or router.
3. **Admin → Plugins → MC Router → Settings**: configure the suffix and approved
   allocation pools. The public demo offers dry-run only; real connection settings
   are deliberately unavailable there.

There is no separate routing page anymore. Nothing is added to console/files.
Only root admins can access these controls in this alpha.

## Disposable local harness

From the repository root:

```sh
nix develop -c bash scripts/setup-panel.sh
nix develop -c bash scripts/setup-router.sh
nix run .#test
nix develop -c bash scripts/integration.sh
paseo script start dev --cwd "$PWD"
```

The harness pins Panel beta38 and mc-router v1.47.1. It installs the plugin through
Panel's plugin command and builds assets, all under ignored `.dev/`. No production
checkout, environment, database or credentials are copied.

For a temporary public preview, run `paseshare <assigned-port> --detach` and write
only its HTTPS origin (no trailing slash) to `.dev/public-url`. The disposable
development router reads this on each request, keeping asset/signed-link origins
correct without losing Paseo's service port.

```sh
nix develop -c php scripts/setup-preview.php
```

This adds fake sample nodes/allocations/servers, enforces dry-run, and provisions
the test admin if missing. Existing passwords are preserved. Use `--reset-access`
only when requested; its signed password-setup link expires after 60 minutes.
Do not put real backups, secrets or Wings credentials into the public demo.

Optional Linux browser smoke test (temporary separate account, automatically
removed; requires a running preview and `.dev/public-url`):

```sh
nix develop .#browser -c bash scripts/browser-smoke.sh
```

The browser shell includes Chromium; pinned Playwright dependencies are installed
from `tests/browser-harness/package-lock.json`. A trusted local Chromium can also
be used with `PLAYWRIGHT_CHROMIUM_EXECUTABLE` in the default shell. No sudo or
system browser-dependency installation is needed.

## Operator configuration

Use **Admin → Plugins → MC Router → Settings**. Approve allocation **IDs**, grouped
by node—not arbitrary port numbers. Occupied allocations may be included; only
free or already-owned eligible allocations are selectable. Backend addresses
must be actual unicast Wings addresses, not `0.0.0.0`, multicast, UI aliases or
public router hostnames. Verify reachability from the router separately.

Dry-run is the default. Live SSH mode needs a reviewed restricted forwarding
account, Panel-local dedicated key file and pinned known_hosts file. The form
accepts **file paths only**, never private-key contents or general root credentials.
Local HTTP mode is restricted to disposable local/testing application environments.
Changing a connection/suffix while confirmed or uncertain live routes remain is
blocked; remove managed routes through their original connection first.

Normal deployment also needs working Panel queue workers and scheduler. The
plugin schedules reconciliation every minute. `php artisan mc-router:reconcile`
queues a manual pass. Do not run that against production without authorization.

## Importable archive and rollback

```sh
nix develop -c python3 scripts/package.py
```

Output: `dist/mc-router-0.2.0-dev.zip`. Only plugin code, license and these
instructions are included—not credentials, harness, runtime metadata or a database.

Panel supports **Admin → Plugins → Import → Install**. Installation changes plugin
files/metadata, runs migrations and may build assets. It has been tested only on
the disposable Panel. A live installation still needs explicit approval and a
backup, including the plugin tables and the router config.

Disable routing per server and let reconciliation remove all owned/uncertain
routes before uninstalling. Migration rollback refuses to discard tracked live
claims. Disable the plugin only after cleanup; disabling it does not remove routes
and stops reconciliation. Resolve external ownership conflicts explicitly rather
than deleting unrelated mappings. Restart workers after approved plugin updates.
Restoring an older alpha ZIP may be incompatible with the new database schema;
restore a matching backup rather than blindly downgrading.

## Verification limits

See the milestone findings for evidence and remaining gates. Active indicates
**effective runtime mapping**, not game-online state or guaranteed persistence.
mc-router can report success despite a failed file save. Before live deployment,
verify router-owned writable configuration, snapshots, restart persistence and
external Minecraft status/join behavior on an explicitly approved disposable game.
