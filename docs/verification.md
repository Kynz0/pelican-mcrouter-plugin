# Verification plan

Local first, live later. Nothing here runs against production until the user authorizes that specific step. "Verified" claims must cite the command/output that proved them.

## 0. Workspace checks (run now, keep green)

- `nix develop -c true` succeeds (store warm).
- `nix develop -c python3 scripts/check_docs.py` passes (docs skeleton, links, references).
- `nix develop -c .#test` runs the same checker.
- Paseo `docs` service serves the repo on `$PASEO_PORT`; `curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$PORT/docs/README.md"` returns 200.

## 1. Plugin spike (disposable, no live writes)

- Generate a fresh plugin skeleton with `php artisan p:plugin:make` against a disposable Panel checkout matching **v1.0.0-beta38**; plugin declares `panel_version` compatibility. Prove install/uninstall via the plugin system, not manual file hacks, if possible; otherwise document the manual path.
- Prove the extra `CreateServer` wizard step appears (Before/After Information), renders the toggle/slug/allocation fields, and that toggling on/off does not corrupt normal creation. Verify field values reach `handleRecordCreation(array $data)` robustly (dehydration, LiveWire update cycles, back/forward through wizard).
- Prove the step can suggest a slug from the name, recompute while untouched, stop auto-following once the admin edits it, and preview `slug.mc.kynzo.org`.
- Prove primary allocation selectability: free allocations of the **router-eligible pool on the selected node**, with eligibility defined by explicit configuration (node + approved game port ranges), and clear messaging when the pool is empty that normal allocation selection remains functional when toggle is off.
- Uniqueness: two rapid creations with the same slug; second must fail clearly, release nothing, and message the admin. Same for two admins racing on the last free pool allocation.

## 2. Allocation & lifecycle integration (staging or disposable server)

- Create server with toggle on → server created normally; desired route stored against server UUID; status `Pending`.
- Apply path marks route `Active` **only after** reading back the effective mapping from mc-router (`FQDN → expected private IP:port`).
- Change the primary allocation (or node if supported by beta38) → desired backend recomputed, mapping updated, old route gone.
- Delete the server → route removed; slug released; no orphan entries; unrelated routes untouched.
- Force reconciliation correctness: kill the router briefly, break/skip an update, then confirm the periodic reconciler repairs `Pending`/`Error`→`Active` and removes routes for deleted servers (no resurrection of stale mappings).
- Toggle off (later versions may allow re-enable) → route removed without destroying the server.

## 3. Router helper (staging file first, live after approval)

- Unit-test hostname validation (suffix `mc.kynzo.org`, DNS label rules, reserved names denylist), backend validation (private Wing node IPs; approved ports), unknown action rejection, authenticated-request requirement.
- Atomic write test: concurrent writers, invalid JSON rejection, replacement-and-rename, last-good snapshot availability. **Test on a staging routes file and a test-watched router instance or offline dry-run; never the live `routes.json` until the user approves that step.** Verify unrelated routes (e.g. `swan.kynzo.org`) survive.
- Readback check: filtered parse of active mappings to confirm the applied route really loaded in v1.47.1 logs/config watch.

## 4. Live end-to-end (requires explicit user approval per step)

- One DNS-only `*.mc.kynzo.org` A/AAAA record to the **current** WAN IP via api token (tool: Cloudflare). Discovery of the exact WAN IP via router CT egress must be rechecked live.
- A fresh disposable test server created via the plugin flow; join address `slug.mc.kynzo.org` TCP 25565.
- LAN routing test: Python Minecraft Java status handshake against the mc-router host with `server=slug.mc.kynzo.org` (see existing probe in the host skills; TCP 25565/UDP add-on allocations were verified separately).
- External validation: independent public TCP check (check-host.net multi-node) + an uncached `https://api.mcsrvstat.us/3/<slug>` status reflecting the disposable server. `missing-backend` for an unused `*.mc.kynzo.org` name must **not** fall through to another server.
- Player login performed by the user from an external network is the final human gate (worth doing once; do not claim it).

## Acceptance

- All general invariants from `product-brief.md` hold (tested or argued); normal disabled-toggle path untouched; failure states never show fake success; docs updated to match what was actually built; local checks green before any live change.
