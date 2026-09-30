# Deployment topology (observed, recheck live)

| Component | Address / role | Observed software and state |
| --- | --- | --- |
| `pelican-panel` | `192.168.1.163`, `https://pelican.kynzo.org` via existing Cloudflare HTTP Tunnel | Debian 13 LXC, Panel v1.0.0-beta38 at `/var/www/pelican`, Nginx/PHP 8.4, SQLite, database queue, cron scheduler. User-owned admin login; never inspect/output `.env` values. |
| `pelican-wings-hdd` | `192.168.1.229`, Panel node ID 1 | Wings v1.0.0-beta29, Docker CE, 160G game mount on `tank` at `/var/lib/pelican`, 64G local backup mount on `tank` at `/mnt/pelican-backups`. Public HTTPS node API via Cloudflare Tunnel; LAN daemon port 8080 and SFTP port 2022. |
| `pelican-wings-ssd` | `192.168.1.147`, Panel node ID 2 | Wings v1.0.0-beta29, Docker CE, 36G game mount `rpool/data`, 64G local backup mount on `tank`; same API/SFTP arrangement. Physical SSD backing was not independently verified inside guest. |
| `mc-router` | `192.168.1.204:25565` | Dedicated Debian 13 CT, official itzg/mc-router v1.47.1; unprivileged systemd user; root-owned `/etc/mc-router/routes.json`, `-routes-config-watch`; systemd service enabled. Public WAN TCP 25565 forward to this CT (user controls LuCI). |

Observed Panel nodes had ten existing candidate **game** allocations each, `192.168.1.229:25565–25574` and `192.168.1.147:25565–25574` (2026-09-30). The admin subsequently added extra ports in separate ranges for mods: `26100–26109` on HDD and `26110–26119` on SSD; `swan` also has an assigned UDP voice allocation `192.168.1.229:24454`. **Query the live Panel before use**: do not infer allocation eligibility only from port numbers, and never choose an assigned port. The game primary may have a numeric port other than the public router's 25565.

A Fabric test server `swan` exists on HDD node with primary `192.168.1.229:25565`. Before this plugin, mc-router's routes file contained `swan.kynzo.org` → that backend. The user validated a real external player join and Simple Voice Chat after assigning/forwarding UDP 24454. The user does not need this old route migrated as a plugin feature; preserve it during work until separately approved otherwise.

DNS `swan.kynzo.org` is an exact, **DNS-only** A record to the home's WAN IPv4 as of the external test; it is temporary and may become stale if the WAN IP changes. The proposed `*.mc.kynzo.org` wildcard was **not yet created** when last checked. DNS-only is essential for ordinary Minecraft TCP; the Panel/Wings HTTPS hostnames use the existing Cloudflare Tunnel. Do not mistake one for the other, or expose Wings HTTP/SFTP via a new game record.

SSH guest inventory and operational rules are in the host's `ssh-guest-access`, `pelican-panel-ops`, `pelican-wings-hdd-ops`, `pelican-wings-ssd-ops`, and `mc-router-ops` skills. The user authorized setup inside those CTs in earlier work, but **not an automatic grant** to modify router, Cloudflare, Proxmox, secrets, or production game state for this new project. No WAN IP, tunnel token, node token, API key, or SSH key is embedded here.
