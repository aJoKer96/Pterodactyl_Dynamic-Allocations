# Dynamic Allocations v0.4.1 — native integration (hotfix)

**Target: Pterodactyl Panel 2.0.0-beta.3 / SDK 2.0.0-beta.6, PHP 8.3/8.4, MariaDB/MySQL, Wings 1.13.5.** This release changes the existing Admin → Servers → New Server and Build Configuration forms. The beta.3 SDK does not expose these admin form components as overridable views, so this is a two-part installation: a native `.pteroext` backend and a version-pinned frontend source patch. Installing only the extension does **not** replace the stock forms.

No additional Wings/host service, Wings fork, Docker socket, database migration or panel PHP core modification. The separate v0.3 creation screen becomes **Dynamic Allocations Settings**, for optional node IPv4 overrides. All other native form sections, extension fields, native activity logging and query invalidations are retained. Native node allocation inventory remains available for diagnostics; pre-creating entries is no longer necessary. Client-side network UI and native server transfers are unchanged.

## Upgrade from v0.4.0 (no frontend rebuild)

v0.4.1 incorporates the confirmed route-binding fix: `{server:admin_identifier}`, `{node:id}`, `{egg:id}`. Pterodactyl's base model uses UUID route keys by default, whereas the extension sends numeric IDs. The native UI patch and allocation logic are unchanged. The extension settings screen version label and fresh-install deployment version guard are updated.

On an already patched v0.4.0 panel, keep the previous extension archive and the **original frontend rollback backup** (for example `/root/pterodactyl-da-040-backup`). Back up the panel database. Install only the new extension package from the extension manager or run:

```bash
cd /var/www/pterodactyl
runuser -u www-data -- php artisan p:extension:install /tmp/dynamic-allocations-0.4.1.pteroext --replace --enable
runuser -u www-data -- php artisan optimize:clear
systemctl reload php8.3-fpm
runuser -u www-data -- php artisan p:extension:list
```

Reload the browser and verify both native allocation forms. Do **not** rebuild or redeploy the existing frontend for this upgrade. If rolling back the frontend later, use the backup from its original deployment, not a new empty backup directory. v0.4.0 without the route fix has the known HTTP 404 issue.

## Installation (test panel first)

Keep a verified panel/database backup and previous extension package. Test on a separate node and panel copy, not a cloned panel connected to production Wings. The core workflow was successfully tested by the maintainer on beta.3 with v0.4.0 and the route-binding hotfix. This is still a beta-targeted release, not a general production compatibility guarantee. Use a single root administrator; do not provision or reassign ports concurrently from other native/API paths or start/transfer servers while editing bindings.

### 1. Build the native frontend in a separate checkout

Extract the **source ZIP**, not the `.pteroext`, on a Linux system (WSL also works). Use a trusted regular user with Python 3, git, Node.js >=22.12 and npm, internet access and sufficient disk/RAM. Do not install npm dependencies as root. No Node.js process will be needed after building. Use a new path that does not yet exist:

```bash
bash patch/build.sh "$HOME/pterodactyl-da-041-build"
```

The script clones the official exact `v2.0.0-beta.3` tag, checks the Git blob SHA-1s of all three changed files, applies the patch, runs `npm ci`, `npm run tsc` and `npm run build:production` (including upstream extension runtime checks). It writes a successful-build marker only after all stages succeed. Nothing in `/var/www/pterodactyl` is touched. If any stage fails, stop and report the exact output. Do not bypass hash or typecheck gates. To retry with a fresh checkout, use a different build directory.

The patch modifies `resources/scripts/components/admin/servers/CreateServerForm.tsx`, `ServerBuildTab.tsx`, `resources/scripts/api/admin/servers/queries.ts`, and adds `DynamicNetwork.tsx` under the admin servers components. The existing mutations retain their native success/error handlers and cache invalidation; patched submissions route through the extension backend.

### 2. Upgrade and enable the extension

Upload `dynamic-allocations-0.4.1.pteroext` through Admin → Extensions, confirm replacement and enable. Or copy the package to a location readable by www-data and run on the panel host:

```bash
cd /var/www/pterodactyl
runuser -u www-data -- php artisan p:extension:install /tmp/dynamic-allocations-0.4.1.pteroext --replace --enable
runuser -u www-data -- php artisan p:extension:list
```

Ensure the extension is enabled and the version is 0.4.1. Existing node overrides and per-egg presets retain the same storage keys. The extension manager settings page no longer creates servers; until the patch is deployed, native creation remains unchanged. If the settings page cannot load, resolve that before deployment.

### 3. Deploy the built frontend (on panel host as root)

Use the source ZIP's `patch/deploy.py` and the successful build checkout from step 1. If built elsewhere, transfer the trusted build checkout (the built assets, success marker and patched source file must be present; node_modules is not required for deployment). Use absolute paths, a new backup location outside the panel directory and a brief maintenance window. The panel must use file-based Laravel maintenance mode (the default).

```bash
cd /var/www/pterodactyl
runuser -u www-data -- php artisan down
python3 /path/to/source/patch/deploy.py install /path/to/pterodactyl-da-041-build /var/www/pterodactyl /root/pterodactyl-da-041-backup
runuser -u www-data -- php artisan optimize:clear
systemctl reload php8.3-fpm
runuser -u www-data -- php artisan up
```

Adjust the PHP-FPM service if the panel runs a different PHP version. If deployment fails, do not ignore the error: inspect the output and confirm the live manifest is still the original before bringing the panel back up. The installer checks the installed version, successful-build marker, extension version, file-based maintenance mode, manifest entries and SRI. It copies only manifest-referenced assets, refuses conflicting existing files, leaves all existing core/extension/theme assets in place, saves the old manifest with its hash/ownership and atomically replaces the manifest last. The live panel PHP files are not edited. Keep the backup and old hashed assets; do not prune assets while rollback is required. Refresh the browser completely (Ctrl+F5) and test. No Wings restart is needed to install the addon.

## Using the native forms

In Admin → Servers → New Server, select the node, choose the node IPv4 or `127.0.0.1`, and enter `80, 443` (or any list of up to 32 distinct comma-separated ports, 1–65535). The first is primary; the rest are additional. No pre-created allocation is required. Node IP comes from a literal or unique IPv4 DNS result for the node FQDN unless behind a proxy. If DNS is ambiguous or points elsewhere, set the actual Wings host bind IPv4 under Dynamic Allocations Settings; this is per node, not per server. Do not use a CDN address. `127.0.0.1` maps to the Docker bridge in typical Wings configurations, not necessarily a host-only binding. Confirm host/firewall reachability separately.

Choose an egg to load stored port defaults. Save/apply/remove defaults explicitly; they do not reserve ports. Selecting another egg or node reloads the network form, so check the ports before submission. For existing servers, stop the server first; open the original Build Configuration, edit the port list/IP and save. The current primary is first, other ports are sorted. A server with multiple existing bind IPs displays the complete list and preserves those bindings on a resource-only save. To convert it to a single IP, explicitly confirm replacement, choose the IP and enter the desired list; duplicate numeric ports must be resolved manually. Existing custom IPs can be left unchanged or migrated to a configured IP. The backend checks an allocation snapshot hash, so a stale form is rejected. It also checks Wings live `offline` state before changing bindings; if unreachable, the change fails. Restart the server through Pterodactyl after a successful network update and inspect actual Docker mappings. Native Wings sync may log failures without reverting the DB change; confirm the mapping before starting another service that uses the released port.

The backend creates missing exact `(node, IP, port)` allocations, reuses free rows and the server's own rows, rejects another server's assigned ports (conservatively even on another IP of the node), computes the add/remove/primary diff, and delegates server creation/update to the native `StoreServerRequest` / `ServerController` and `UpdateServerBuildConfigurationRequest` / `BuildController`. Removed allocations are **unassigned**, not deleted. Other fields are validated by the native request. The existing native UI for inventory is not removed. Privileged ports are restricted to root-admin operations through this extension. A delegated admin without root privileges cannot use the patched allocation UI.

Limitations: no IPv6, wildcard, port ranges, host port scan, TCP/UDP differentiation (Wings maps both), automatic cleanup of unused rows, or cross-node transfer patch. A MariaDB/MySQL advisory lock serializes this extension's operations, but other native paths do not honor it. A server can be started concurrently outside the extension; the offline check does not lock Wings power operations. Avoid concurrent starts, transfers, assignment edits and provision operations. There is no distributed transaction between the panel and Wings. If validation fails after preparing allocations, free rows can remain; they are reusable. If a request times out, check the server list/allocations and Wings before retrying.

## Rollback

With the panel in maintenance mode, restore the old manifest (the script refuses rollback if the current manifest has changed since patch deployment). Then disable or replace the extension. Existing server assignments and settings are not reverted by frontend rollback; restore a database backup only if an explicit DB rollback is needed and no subsequent changes must be kept.

```bash
cd /var/www/pterodactyl
runuser -u www-data -- php artisan down
python3 /path/to/source/patch/deploy.py rollback /var/www/pterodactyl /root/pterodactyl-da-041-backup
runuser -u www-data -- php artisan p:extension:disable dynamic-allocations
runuser -u www-data -- php artisan optimize:clear
systemctl reload php8.3-fpm
runuser -u www-data -- php artisan up
```

The original assets remain in place. For a panel upgrade, restore the stock frontend first; rebuild/review a new patch for the upgraded version rather than carrying beta.3 assets forward. Do not disable this extension while the patched frontend is active unless you are restoring the native manifest. This patch is intentionally version-locked.

## Verification and test checklist

Local checks: PHP syntax, JS syntax, TypeScript JSX syntax/transpilation of the new component, port/IP/preset and allocation snapshot/diff unit tests, Python/shell syntax. Patch anchors and blob hashes compared to upstream beta.3 via GitHub. Deployment/rollback tested on a synthetic asset tree; **full patch application, TypeScript typecheck, npm/Vite build and core workflow were reported successful by the maintainer on the deployed beta.3 panel after the route hotfix; not independently reproduced here**. The build script runs the real typecheck/build on your system and aborts on failure. For v0.4.1, the route registration regression test is additionally run locally; installation of the new archive on a live panel has not been repeated here.

On a disposable panel/node:
1. Verify native New Server has the new fields and all other native fields still work; no existing allocations required. Test first with a free high port and a second high port. Verify stored primary and additional allocation IDs, native details and Docker mapping.
2. Save/reload/reapply an egg preset; switch node and egg. Verify the localhost/public-IP choices, manually configured IPv4 and no allocation creation by saving presets.
3. Reject invalid/duplicate ports, another server's assigned port, invalid IP, non-root API calls and a stale revision. Test native egg tag validation and custom extension fields.
4. Stop the test server; change its primary, add a port, remove a port, and change IP. Verify old rows become unassigned, desired rows assigned, native limits unchanged and restart produces the requested bindings. Try a changed binding while running; it must reject. For a mixed-IP server, test unchanged resource-only save and explicit single-IP conversion.
5. Roll back the frontend and confirm stock forms return without changing server data. Only after these checks test ports 80/443 on a disposable node, checking both TCP and UDP host listeners first.

NGINX Proxy v0.1 still listens on 18080/18443 and expects PROXY protocol. It must be upgraded separately before testing 80/443 with that egg.

## Upstream source references

- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/packages/sdk/views.json
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/resources/scripts/components/admin/servers/CreateServerForm.tsx
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/resources/scripts/components/admin/servers/ServerBuildTab.tsx
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/resources/scripts/api/admin/servers/queries.ts
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Actions/Servers/UpdateServerBuild.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/vite.config.mts
- https://github.com/pterodactyl/wings/blob/v1.13.5/environment/environment.go

- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Models/Model.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Models/Server.php
