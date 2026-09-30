# Trying the dry-run prototype

**Version 0.1.0-dev is a UI/compatibility prototype, not the finished v1.**
It reads Panel nodes, allocations and servers. It does not contact mc-router,
change server ports, reserve hostnames, create servers or write routing records.
Only root admins can access its page; delegated-admin permissions are future work.
Nothing is added to the normal server console/files area.

## Disposable local Panel (recommended)

From the repository root:

```sh
nix develop -c bash scripts/setup-panel.sh
nix develop -c bash scripts/setup-router.sh
nix develop -c php tests/panel.php
nix develop -c python3 tests/router_api.py
nix run .#test
```

The harness pins Panel beta38 and mc-router v1.47.1, installs dependencies,
generates a plugin skeleton, installs this plugin through Panel's plugin command,
and builds Panel assets. Everything lives under ignored `.dev/`.
No production checkout, secrets or database are copied.

Create a local admin interactively (choose your own disposable password):

```sh
nix develop -c bash -c 'cd .dev/panel && php artisan p:user:make'
paseo script start dev --cwd "$PWD"
```

Open the assigned local Paseo service address. The dev script uses the assigned
Paseo URL for asset origins; `MC_ROUTER_PREVIEW_URL` can override it when using a
separate preview address. Set up disposable nodes and
allocations in the local admin area. No actual Wings is required for previewing
allocations; **do not enter production Wings tokens**.

## Configuration

The prototype uses prefixed configuration variables (in the disposable Panel's
local environment):

```text
MC_ROUTER_SUFFIX=mc.example.org
MC_ROUTER_POOLS='{"1":[10,11,12],"2":[20,21]}'
```

Pools contain **allocation IDs**, grouped by **node ID**, not raw port numbers.
Use your local allocation IDs. No pool is approved by default. Restart the local
Paseo service after changing configuration. A proper admin settings UI is planned
for v1; these are prototype configuration instructions only.

Under Admin → MC Router (prototype):

- Leave existing server blank to preview a new-server allocation. The first free
  eligible allocation is suggested automatically; the dropdown is an override.
- Choose an existing server to keep its eligible current primary allocation.
  If it is ineligible, a replacement is proposed with a confirmation requirement.
  Even after confirming, this prototype still makes **no changes**.
- Edit the hostname; subsequent name edits no longer replace it.

## Temporary public preview

Start the Paseo `dev` service, then run `paseshare <assigned-port> --detach`.
Write only the resulting HTTPS origin (no trailing slash) to ignored
`.dev/public-url`. The local development router reads it on each request so
Panel assets and signed links use the tunnel address without changing ports.

`nix develop -c php scripts/setup-preview.php` adds sample documentation-range
nodes, free/occupied allocations, eligible/ineligible existing servers and a
synthetic test admin. It trusts only the loopback proxy and prints a signed
password-setup link so the user can choose their own disposable password. The
link expires after 60 minutes; keep it private. No production credentials or
actual Wings services are used. Do not upload real backups/secrets to this
publicly reachable disposable Panel. The quick-tunnel URL is temporary.

## Importable archive

```sh
nix develop -c python3 scripts/package.py
```

Output: `dist/mc-router-0.1.0-dev.zip`. It contains only the plugin, license and
these instructions—not the harness, configuration, credentials or runtime state.
Panel supports Admin → Plugins → Import → Install. Installation may run build
steps on Panel and change plugin metadata; trying the archive on a live Panel
therefore still needs explicit approval and a backup. **No live install has been
performed or authorized by this prototype's development.**

Disable the plugin to hide the preview. Uninstall removes the prototype; it has
no plugin tables or router routes to clean up. These rollback instructions do
not apply to future versions that actually manage routes.

## Verification limits

The local tests prove Livewire rendering/hydration, suggestion and overrides,
existing-server confirmation messaging, non-admin denial, and custom wizard-step
dehydration. They do not prove successful Wings creation, lifecycle persistence,
allocation races, Minecraft joins, or secure production transport.
