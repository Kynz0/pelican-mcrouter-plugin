# Proposed architecture — validate in a spike

```text
Admin Create wizard (toggle + suggested slug)
        │ validate node, slug, eligibility and capacity
        ▼
Pelican plugin DB: server UUID → desired FQDN, desired primary allocation, status
        │ queued apply + periodic reconciliation
        ▼
Authenticated, narrowly scoped router-side helper (LAN/private channel)
        │ validate + atomically replace /etc/mc-router/routes.json
        ▼
mc-router watches JSON → public TCP 25565 → Wings private IP:primary port
```

This is a proposal, **not implemented**. The plugin's record (server UUID + normalized hostname) is the desired-state source of truth; mc-router effective routes are read back. Do not use the hostname alone as an ownership marker. Preserve routes not owned by the plugin (the existing `swan` manual route) unless explicitly migrated/removed.

## Create path

1. Toggle off → no plugin fields, no reservation, normal Pelican path. Toggle on → suggest `slug` from server name (ASCII lowercase alphanumeric/hyphen, no leading/trailing hyphen, max DNS label length), editable. Show `slug.mc.kynzo.org` preview. Never change the slug again merely because the name field changes after the admin edited it.
2. At submit, revalidate node eligibility, hostname uniqueness (case-insensitive, DB uniqueness constraint), reserved names, and that chosen primary allocation belongs to the node, is in its **game router pool**, and is free. Select/reserve within a database transaction; account for SQLite's serialized writes and possible races. The existing Pelican creation service already assigns allocations, so integrate rather than independently mutating ownership. A rendered dropdown is not a reservation.
3. Create server normally and persist desired route linked to **server UUID**. Do not hold a DB transaction open while waiting for network calls. A queued apply job updates router; UI shows `Pending` until readback confirms FQDN → expected backend. If creation fails, release slug/allocation cleanly; if routing fails, keep the server and show a retryable `Error`, not a misleading active join address.
4. On primary allocation/node change, derive backend afresh from the server, update desired state and reapply. On deletion or opt-out, remove only this plugin-owned route. A periodic reconciler heals missed events, retries, and drift. Retain one-to-one invariant: one server↔one slug for v1.

## Router-side boundary

Use a dedicated local helper or forced-command interface with a small allowlist: the permitted hostname suffix `mc.kynzo.org`, private backend IPs of approved Wings nodes, approved game ports, and actions `apply/remove/read`. Authenticate requests; make route writes atomic (write+fsync+rename with valid JSON), keep a recoverable last-good snapshot, preserve unrelated routes, and confirm mc-router loaded the requested mapping. Protect against replay/stale revisions and concurrent writers. The exact transport (SSH forced command vs restricted authenticated LAN endpoint) is a decision for the coding spike; never expose an unprotected mc-router API on WAN or hand the Panel process general root access.

## Failure and operations

- Desired states: `Pending`, `Active`, `Error` (with safe diagnostic), `Removing`; keep the actual backend and applied revision separate from desired values. Timeouts/retries must be idempotent; stale jobs must not reintroduce old routes after allocation changes or deletion.
- An offline game may still retain an active mapping; 'route active' means routing config accepted, **not** game server online. A stopped/missing backend should fail to connect, not fall through to another server (keep `default-server` absent).
- Wildcard DNS once, DNS-only to current WAN IP. Validate external lookup and TCP game status after authorization. Dynamic WAN IP updates are a separate operational requirement (do not silently pin a historical IP as permanent).
- Existing manual `swan.kynzo.org` lies outside `*.mc.kynzo.org` and need not be migrated for v1. Its deletion is not part of this plugin project unless explicitly requested.
- No automatic management of voice/extra-port pools, cross-protocol forwarding, Cloudflare records per server, or router/LuCI NAT rules in v1.
