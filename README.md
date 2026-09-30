# Pelican × mc-router

Reusable, admin-only Pelican plugin for Minecraft Java hostname routing.
Licensed **AGPL-3.0-or-later**. Targets **Panel v1.0.0-beta38** initially.
Hostname suffixes, Wings nodes and allocation pools are configurable; the
maintainer's network is a test case, not a hardcoded requirement.

**Current milestone: read-only UI/compatibility prototype, not production-ready.**
It previews automatic allocation selection with optional override, keeps eligible
existing-server ports, and asks for confirmation when proposing a replacement.
It does not apply routes or change servers. No live services have been modified.

- [Try the prototype](docs/prototype.md)
- [Verified spike findings](docs/spike-findings.md)
- [Implementation checklist](TODO.md)
- [Documentation map](docs/README.md)

```sh
nix run .#test
nix develop -c bash scripts/setup-panel.sh
nix develop -c php tests/panel.php
nix develop -c bash scripts/setup-router.sh
nix develop -c python3 tests/router_api.py
nix develop -c python3 scripts/package.py
```

Start the disposable Panel with `paseo script start dev --cwd "$PWD"` after
preparing it and creating a local admin. Nix is development tooling, not a
runtime requirement for plugin users. Release archives use Pelican's normal
plugin import/install mechanism.
