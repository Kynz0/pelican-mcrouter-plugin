# Agent instructions

Read `docs/README.md`, then `docs/agent-handoff.md` before designing or coding. The docs separate verified facts from proposals and unknowns. Check the installed Panel **v1.0.0-beta38** source and official docs before assuming a plugin hook exists. Do not patch live Panel core to make the UI work.

This is a planning workspace, **not** a production checkout. Do not install a plugin, edit live DNS/LuCI/Proxmox, alter mc-router routes, change Panel data, or restart a game server without explicit task-scoped approval. Never display or commit `/var/www/pelican/.env`, APP_KEY, API/node tokens, SSH private keys, passwords, or connection strings. Use a disposable server for integration tests; preserve the existing `swan` server unless separately authorized.

The user normally delegates coding via Paseo/OpenCode with a new worktree. Do not merge a worktree branch without permission. Commit author in this repo is configured as `kynzo <kynzo@users.noreply.git.kynzo.org>`; do not change global git identity. Prefer tests and a local/staging harness before any live integration.
