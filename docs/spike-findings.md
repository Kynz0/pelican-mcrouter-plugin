# Compatibility spike findings

## Versions and evidence

Disposable upstream checkouts (not live source):

- Panel `v1.0.0-beta38`, commit `e84a4afd466e32f2b376774365185fe0342b73e6`.
- mc-router `v1.47.1`, commit `175aced3d182734bcefac2eead9fae34d83795d2`.
- Official Pelican plugin guide: https://pelican.dev/docs/panel/advanced/plugins/
- Router API guide: https://github.com/itzg/mc-router/tree/v1.47.1#rest-api

The official Panel guide was read alongside the pinned source. It is a current,
unversioned guide, not proof that every documented feature exists in beta38.
Pelican declares `AGPL-3.0-only`; this plugin's original code is
`AGPL-3.0-or-later`. No Panel core code was copied or patched.

## Panel findings

- `p:plugin:make` generates namespace `Kynzo\\MCRouter`, **not** `App\\Plugins`.
  The plugin manifest controls PSR-4 loading of its `src/` directory.
- `CanCustomizeSteps` adds steps before **all** default steps or after **all**
  default steps, not directly after Information. The older handoff overstated
  where the After hook inserts a step.
- `ServerResource` also supports `registerCustomPages`; a dedicated admin-only
  page or additional admin resource page can manage existing servers without
  appearing in the client/server panel.
- Beta38's server creation service commits the server/allocation transaction
  **before** calling Wings, and deletes on a connection failure. Do not wrap
  the entire service in a plugin transaction and accidentally hold SQLite locks
  over network calls. Durable capture/cleanup still needs implementation.
- Plugin loading is intentionally disabled in normal Panel unit-test mode. Our
  isolated integration script boots the disposable local app, prevents stray
  Laravel HTTP requests, and rolls back all fixture records instead.

Commands run successfully:

```sh
nix develop -c bash scripts/setup-panel.sh
nix develop -c php tests/panel.php
nix develop -c bash scripts/integration.sh
```

The latter proves actual Filament/Livewire form rendering and update cycles,
auto selection, manual override, edited slug retention, keeping an eligible
existing port, confirmation for replacement, no allocation mutation, non-admin
denial, and plugin wizard fields surviving hydration/dehydration. It **does not**
prove those fields are captured by the server creation service or route storage.
The integration runner additionally builds the ZIP and exercises beta38's actual
ZIP importer with a renamed archive, then restores local plugin runtime metadata.

## mc-router findings

`server/api_server.go` implements GET/POST `/routes` and DELETE
`/routes/{serverAddress}`. GET reads effective in-memory mappings, so it provides
better evidence of an applied mapping than reading a config file alone.

API changes also call `RoutesConfigLoader.SaveRoutes()` when `-routes-config` is
configured. A separate file-writing helper is therefore **not inherently
necessary**. The existing proposal was too prescriptive.

Important limitations verified in source and/or test:

- No API authentication/TLS middleware is registered in this version. Its API
  must not be exposed publicly or casually to an untrusted LAN.
- The game-client allow/deny flags are not API authentication.
- Persistence uses `os.WriteFile`, not atomic rename. Save failures are logged,
  but a successful POST still returns 201 and GET shows the in-memory mapping.
  This was reproduced using an unwritable target (directory in place of file).
- Runtime readback therefore cannot prove restart durability.
- API updates preserve unrelated simple mappings in the integration test, and
  survive restart **when the router can write its configured file**.
- API has no ownership marker or compare-and-swap operation. A plugin-side
  ownership ledger, serialized jobs and conflict handling are required;
  concurrent external writers cannot be made race-free through this API alone.
- Saving rewrites the whole config using the known schema. More elaborate router
  configurations/discovery setups need compatibility testing before support.

Command run successfully:

```sh
nix develop -c python3 tests/router_api.py
```

It starts only a finite disposable test process, uses temporary files and a
loopback API, tests apply/read/update/delete, unrelated-route preservation,
restart persistence, and persistence false-success. No game backend is contacted.

## Production transport remains a design gate

Prefer **plugin + mc-router**, without custom companion service. Candidate secure
transport: mc-router's loopback API over restricted SSH forwarding, using an
existing SSH daemon; or an operator-provided authenticated private endpoint.
Direct plain HTTP is suitable for the disposable loopback test, not a secure
production default. Any deployment requiring firewall, SSH-account, file
permission or systemd changes must be separately approved. The recorded live
root-owned routes file and strict service sandbox may prevent API persistence;
we have not altered or re-inspected them.
