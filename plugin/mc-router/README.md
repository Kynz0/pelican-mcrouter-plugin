# MC Router — Pelican Panel plugin

Admin-only Minecraft routing for [Pelican Panel](https://pelican.dev) and
[itzg's mc-router](https://github.com/itzg/mc-router). MC Router adds a routing
step to the server-creation wizard and a routing tab to the admin server-edit
page. When an admin enables routing for a Minecraft server, the plugin
registers a hostname (for example `myserver.mc.example.org`) with the
mc-router API so Minecraft clients can pass through mc-router to reach the
server — no extra port forwarding per server.

- No client-area controls, no game console/file access, no companion service.
- Dry-run by default: nothing leaves the Panel until a live transport is
  explicitly configured and saved.
- Auto-updates: releases are published with an `update.json` feed
  (`update_url` in `plugin.json`), so the Panel's plugin page shows an
  in-panel Update action when a newer release exists; `php artisan
  p:plugin:update mc-router` works too.
- csak requires the mc-router REST API; there is no mc-router patching.

> Compatibility: Panel `v1.0.0-beta38`, mc-router `v1.47.1`. Other versions
> are untested.

## Requirements

1. **Pelican Panel v1.0.0-beta38** with the standard cron scheduler and queue
   worker running (the official install guide already sets both up; they power
   emails and scheduled tasks in stock Panel too).
2. **mc-router** in front of your Minecraft servers, reachable from the Panel
   host in one of the ways described under *Setup*.
3. A **wildcard DNS record** for your chosen hostname suffix (for example
   `*.mc.example.org`) pointing at the address clients use to reach
   mc-router's listener port, plus appropriate TCP forwarding/port
   publication. DNS and firewalling stay outside this plugin.

## How it works

- The plugin **never logs in to anything** and runs no commands on mc-router.
  It talks to the mc-router REST API (the same one its web UI uses) to read,
  create and delete hostname→backend routes.
- mc-router's API has **no authentication** in v1.47.1. The plugin
  deliberately constrains how it reaches that API so only the Panel can use
  it (SSH tunnel to loopback) or so you accept a documented risk (plain HTTP).
- Route changes are recorded in the plugin's own tables first, applied by a
  queued job, and reconciled once per minute against what the router reports.
  Your existing manual routes (e.g. an old hand-created one) are never
  touched.
- If the router is unreachable, routes stay `Pending`/`Error` and retry;
  nothing in the Panel restarts or stops game servers.
- The server-edit MC Router tab has a **Verify end-to-end** action: it reads
  the mapping back from the router, resolves the join address via DNS, and
  opens a TCP connection through the router's public port — a green result
  means the whole chain works from the Panel host.

## Setup

Admin → Plugins → MC Router → Settings:

1. Choose a **hostname suffix** (for example `mc.example.org`) and set up the
   matching wildcard DNS record outside the plugin.
2. Configure the primary-allocation pools per Wings node — only allocations
   you add there are offered for routing. The pool editor can add existing
   allocations by port range, show which server occupies each port, create
   brand-new allocations in a range of your choice, or auto-generate a
   brand-new range of uncommon free ports on the node.
3. List the eggs that should get the routing step/tab (saving installs a
   `mc-router` feature on those eggs; the step only appears on eligible eggs).
4. Pick a **router transport** and finish its subsection below.
5. Use *Test saved router connection* to confirm the Panel can reach the
   router API.

### Transport A — mc-router on the same host as the Panel (HTTP)

Zero extra accounts; the plugin calls `127.0.0.1` directly.

1. In mc-router's config (systemd unit or compose file), set
   `API_BINDING=127.0.0.1:25564` (or `-api-binding 127.0.0.1:25564`) and
   restart mc-router.
2. Make sure the router's routes file is writable by the service user
   (root-owned routes fail persistence — the API will still answer, but
   routes are lost on restart).
3. Because the API is on loopback but reachable without SSH, same-host
   setups use the same opt-in as Transport C: set
   `MC_ROUTER_ALLOW_INSECURE_HTTP=true` in the Panel `.env`, then in plugin
   settings pick **Plain HTTP API URL** with host `127.0.0.1` (or the Panel
   host's own name), port `25564`.

   This explicit opt-in means the (unauthenticated) API is only reachable
   because you chose to bind it — loopback binding + firewall keeps it local;
   the env flag records that you accept an unauthenticated localhost API.

### Transport B — mc-router on a different host (restricted SSH tunnel)

The Panel opens a one-shot `ssh -W` tunnel to the router host and speaks the
API through it. The SSH account is deliberately gutted: it cannot run
commands, has no TTY, and may only forward to `127.0.0.1:<api-port>` on the
router host.

In the plugin settings pick **Restricted SSH forwarding** and use the
**SSH setup guide** button right below the transport selection: it shows
every step with exact copy-paste commands, and can generate the dedicated
keypair on the Panel host for you (`php artisan mc-router:ssh-setup
[--generate]` does the same from the CLI).

Full manual walkthrough, if you prefer:

1. On the **router host**, create a locked-down account (example for Debian):
   ```sh
   sudo useradd -m -s /usr/sbin/nologin mcrouter-api
   sudo -u mcrouter-api mkdir -p ~mcrouter-api/.ssh && sudo -u mcrouter-api chmod 700 ~mcrouter-api/.ssh
   ```
2. On the **Panel host**, generate a dedicated keypair and authorize the
   public key on the router host with forwarding restrictions (or run
   `php artisan mc-router:ssh-setup --generate` on the Panel host to create
   the keypair and print the exact copy-paste lines for every step):
   ```sh
   ssh-keygen -t ed25519 -f ~/.ssh/mcrouter_api_key -N '' -C mcrouter-api
   # append to ~mcrouter-api/.ssh/authorized_keys on the router host:
   restrict,port-forwarding,permitopen="127.0.0.1:25564" ssh-ed25519 AAAA... mcrouter-api
   ```
3. Pin the router host key so the Panel never accepts a changed one:
   ```sh
   ssh-keyscan -p 22 router.example.org > ~/.ssh/mcrouter_known_hosts
   ```
4. Point mc-router's API at loopback on the router host
   (`API_BINDING=127.0.0.1:25564`), restart it, and make sure the router's
   routes file is writable by its service user.
5. In the plugin settings pick **Restricted SSH forwarding**, and fill in:
   SSH host, SSH port (22), the `mcrouter-api` account, api port `25564`,
   the panel-local private key path (`/home/.../mcrouter_api_key.pem`), the
   panel-local `known_hosts` path, then *Test saved router connection*.

`sshd` on the router host must allow local TCP forwarding for this account
(`AllowTcpForwarding local` is default in OpenSSH; if you globally set
`AllowTcpForwarding no`, add a `Match User mcrouter-api` block with
`AllowTcpForwarding local`).

### Transport C — plain HTTP(S) API URL (advanced, opt-in)

For setups where SSH is impractical (some shared hosting, Windows router
hosts, containers without sshd). The API stays unauthenticated, so anyone who
can *reach* that port can rewrite your routes — put it behind a firewall,
VPN, or reverse proxy with an allowlist.

1. Enable the escape hatch in the Panel's `.env`:
   `MC_ROUTER_ALLOW_INSECURE_HTTP=true`.
2. Point mc-router's API at a reachable interface
   (`-api-binding <lan-ip>:25564`) and firewall that port to your Panel host.
3. In the plugin settings pick **Plain HTTP API URL**, enter the router
   host/address (no scheme), port, and optionally enable HTTPS (self-signed
   certificates accepted; certificate identity is not verified).

## CLI override (optional)

Every setting can be pinned from the environment instead of the slide-over —
useful for infrastructure-as-code:

```
MC_ROUTER_SUFFIX=mc.example.org
MC_ROUTER_POOLS={"1":[10,11,12]}   # node ID → allocation IDs
```

## Console address port hiding (temporary)

On the server console, Pelican shows `join-address:port` (e.g.
`myserver.mc.example.org:25567`). That port is the *backend* port — Minecraft
clients join via the default port, so the suffix is confusing. MC Router
therefore hides the port on the console **for routed servers only**, via a
small injected script. Clicking the Address block copies the join address
**without** the port (the hook wraps the panel's clipboard call). Set
`MC_ROUTER_HIDE_CONSOLE_PORT=false` in the Panel
`.env` to turn it off instantly).

**This is a documented temporary stopgap.** Pelican core renders the address
and beta38 has no clean plugin hook for it. Upstream PR
[pelican/panel#2511](https://github.com/pelican/panel/pull/2511)
("Add allocation port visibility option") was merged into main on 2026-08-22 —
after the newest release, beta38 — so a native per-allocation "Show port"
toggle will ship in the next Panel release. When your Panel is upgraded past
it, remove the plugin's hook: delete
`src/Filament/ConsoleAddressHook.php`, remove the
`registerRenderHook(...)` block in `src/Providers/McRouterProvider.php`, and
delete the `hide_console_port` line in `config/mc-router.php`. The hook fails
open (if Pelican changes its markup, it silently does nothing).

## Lifecycle & guarantees

- **Dry-run by default.** Choose routing on a server, save it, and nothing
  touches existing allocations or the router; the plugin ledger shows the
  desired state as `pending` without applying it.
- Switching to a live transport with confirmed routes locks suffix/transport
  edits until routes are disabled/removed — prevents stranding `A` records
  against renamed backends.
- Existing server routes shown with status `Active` were confirmed by
  readback from mc-router itself, not just recorded intentions.
- Deleting a server removes its route and releases the hostname; crashed
  syncs are retried (3×) and reconciled every minute.
- Foreign/manual routes (like a pre-existing `swan.mc.example.org`) are never
  adopted or modified.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Route stays `Pending` | Queue worker not running (`php artisan queue:work`), or SSH/HTTP failure — check Panel logs |
| *Test saved router connection* fails | Wrong API binding/port, routes file unwritable, or known-hosts mismatch |
| Route on the router disappears on restart | mc-router's routes file not writable by the service user |
| Hostname doesn't resolve outside | Missing/wrong-wildcard DNS record, or DNS-only vs proxied confusion (Cloudflare proxy is not suitable for Minecraft TCP) |
| Player connects but "connection refused" | Backend server offline or allocation pool no longer matches node topology |

See `docs/` in the plugin repository for the full architecture and live
deployment checklist.
