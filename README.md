# Pelican × mc-router integration

Planning workspace for an **admin-only Pelican plugin** that gives a newly created Minecraft server a public `slug.mc.kynzo.org` address through the existing mc-router CT. No plugin code has been written or installed yet.

Start with [docs/README.md](docs/README.md). The handoff is [docs/agent-handoff.md](docs/agent-handoff.md). The production inventory here is observational and must be rechecked; this repository contains no credentials or deployment configuration.

The requested flow: Admin → Servers → Create, `Use mc-router` toggle (off by default), editable suggested slug and preview. If enabled, choose a free **primary** allocation from an approved router pool on the selected Wings node; register `slug.mc.kynzo.org` → its private IP:port. Game clients still connect on public TCP 25565. Extra mod/voice ports are independent and out of scope for v1.

Run `nix develop -c python3 scripts/check_docs.py` to validate the documentation skeleton. A docs-only preview is available through the Paseo `docs` service; no production services are started here.
