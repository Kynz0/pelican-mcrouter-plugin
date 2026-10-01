# Agent instructions

Read `docs/README.md`, then `docs/agent-handoff.md` before designing or coding. The docs separate verified facts from proposals and unknowns. Check the installed Panel **v1.0.0-beta38** source and official docs before assuming a plugin hook exists. Do not patch live Panel core to make the UI work.

This is a planning workspace, **not** a production checkout. Do not install a plugin, edit live DNS/LuCI/Proxmox, alter mc-router routes, change Panel data, or restart a game server without explicit task-scoped approval. Never display or commit `/var/www/pelican/.env`, APP_KEY, API/node tokens, SSH private keys, passwords, or connection strings. Use a disposable server for integration tests; preserve the existing `swan` server unless separately authorized.

The user normally delegates coding via Paseo/OpenCode with a new worktree. Do not merge a worktree branch without permission. Use the checkout's configured git identity for commits; no identity is hardcoded in tooling. Prefer tests and a local/staging harness before any live integration.

## Test preview access
The user wants each preview ready to use: pre-create/reuse a disposable test admin with a simple username (`preview`) and sample data; do not send them to Artisan to create an account. They prefer basic test credentials. Keep credentials outside git, never reuse production credentials, and follow the no-password-display rule above. For a publicly exposed root-admin preview, use a strong user-chosen password and a private, expiring password-setup link when access must be provisioned. Preserve the existing preview account/password unless the user requests a reset. Keep the public preview in forced dry-run mode.
