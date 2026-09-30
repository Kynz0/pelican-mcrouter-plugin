# Product brief and agreed behavior

## Goal

An **admin-only** Pelican plugin for the existing `/admin/servers/create` workflow. Creating a Minecraft server with `Use mc-router` enabled should produce a usable `slug.mc.kynzo.org` join address on public TCP 25565 without manual DNS records or manual router-file edits for every server. Normal creation must work unchanged when the toggle is off.

## V1 requirements agreed with user

1. A `Use mc-router` toggle, default off. When on, show an editable slug, suggested from the server name (lowercase DNS-safe formatting) and a live `slug.mc.kynzo.org` preview. Admin-only; non-admin server-owner UI is later work.
2. The admin still selects a Wings node. The plugin selects a **free primary allocation from a predefined mc-router-eligible pool on that node**, rather than using a random add-on port. The current candidate Minecraft primary pools are 25565–25574 on each node; recheck and define eligibility explicitly, not by hard-coded number alone.
3. Register a hostname → private allocation IP:port mapping with mc-router. All public Minecraft addresses use **TCP 25565** at the router; backend allocation ports need not match that external port. An existing server's route should follow changes to its primary allocation or node. Remove stale routes on deletion/opt-out.
4. Use one DNS-only wildcard `*.mc.kynzo.org` pointing at the current WAN IP, **after** testing in an explicitly authorized step. This is distinct from the existing Cloudflare HTTP Tunnel for Panel and Wings. DNS wildcard setup and WAN-IP drift handling are infrastructure tasks; no per-server Cloudflare API calls in plugin v1.
5. Router-side changes go through a narrowly scoped mechanism, not broad root SSH or a public, unauthenticated mc-router management API. Report pending/active/error state rather than claiming success before a route is applied and observed.
6. A new disposable server is the integration target. The user does not require preserving the existing `swan.kynzo.org` route as a plugin migration priority, but do **not** silently delete or break the running `swan` server during development.

## UX caveat

The user prefers the toggle/slug directly in the first creation step, above the allocation fields. In installed beta38 the straightforward supported plugin hook adds a **whole wizard step**, not fields inside Information. User explicitly accepts an extra step for v1. Prototype the exact flow first: the extra step must not silently override a previously chosen allocation or display a misleading unfiltered primary selector. If a small upstream extension hook is needed for clean first-step placement, propose it separately rather than copying the entire core page.

## Out of scope v1

- Child/self-service creation UI and quotas; voice chat / other add-on port automation; automatic Minecraft wake/sleep; arbitrary Minecraft proxy replacement; per-server DNS writes; native Bedrock routing; Proxmox/LuCI/Cloudflare changes without scoped approval.
- The extra mod port pools (e.g. HDD 26100–26109, SSD 26110–26119, plus legacy `swan` UDP 24454) are **not** game primary allocations. Do not give them to the mc-router primary selector.

## Invariants

- A hostname has at most one owning server. A server has at most one active v1 hostname. Validate and reserve case-insensitively at submit time, not solely in the browser.
- Never route an unallocated, cross-node, privately unreachable, or non-owned backend. Preserve normal Pelican creation when toggle is off.
- Route failure must not claim success or orphan a permanently reserved slug. Define retry/reconciliation and administrative error visibility.
