# Pelican × mc-router

Reusable, **admin-only**, **AGPL-3.0-or-later** plugin for Minecraft Java hostname
routing. Initially targets **Pelican Panel v1.0.0-beta38** and **mc-router v1.47.1**.
Domains, nodes and eligible allocations are configurable, not tailored to one network.

**Current: 0.2.0-dev experimental alpha.** Native server-creation wizard step,
admin server-edit tab, automatically populated primary selector, specific
replacement confirmation, plugin settings and queued native-API synchronization.
Dry-run is the default; the public demo is forced dry-run. No live services have
been modified and no production installation is approved.

- [Try the alpha / installation instructions](docs/prototype.md)
- [Milestone 2 findings and remaining gates](docs/milestone2.md)
- [Original compatibility spike](docs/spike-findings.md)
- [Implementation checklist](TODO.md)
- [Documentation map](docs/README.md)

```sh
nix run .#test
nix develop -c bash scripts/setup-panel.sh
nix develop -c bash scripts/setup-router.sh
nix develop -c bash scripts/integration.sh
nix develop -c python3 scripts/package.py
```

Start the disposable Panel with `paseo script start dev --cwd "$PWD"`.
Optional browser checks use `nix develop .#browser -c bash scripts/browser-smoke.sh`.

Nix is development tooling, not a plugin runtime requirement. Live transport uses
OpenSSH restricted forwarding to mc-router's loopback API, without a custom
companion service. It requires a trusted Panel and correctly restricted/pinned
SSH access; see the documented trust and persistence limitations before deployment.
