# Dynamic Allocations v0.3.0 — preview

Native Pterodactyl Panel extension for **2.0.0-beta.3 / SDK 2.0.0-beta.6**. No Wings patch, host service, additional database migration or core file modification. Admin > Dynamic Allocations offers a separate creation screen; the stock creation screen and node allocation inventory are unchanged. The SDK in beta.3 cannot replace the native allocation box. The extension is intended for a single administrator on a test panel first.

## Installation

Upload the accompanying `.pteroext` (ZIP with the runtime manifest at its root) through Admin > Extensions > Install. Enable only on the test panel, or use CLI from `/var/www/pterodactyl`:

```sh
php artisan p:extension:install /path/to/dynamic-allocations-0.3.0.pteroext
chown -R www-data:www-data extensions public/assets/extensions
php artisan p:extension:enable dynamic-allocations
php artisan p:extension:list
```

Alternatively the source directory can be checked and packaged using the Panel's own `php artisan p:extension:pack /path/to/dynamic-allocations --output=/tmp/dynamic-allocations.pteroext`. The frontend is a plain JavaScript ESM module using the SDK and React from the panel import map; no local npm build necessary. Disable with `php artisan p:extension:disable dynamic-allocations`. If extension loading causes problems, set `PTERODACTYL_EXTENSIONS_ENABLED=false` in `.env`, clear configuration cache and reload PHP-FPM. Existing allocations and servers remain intact.

## Upgrade from v0.1.0 or v0.2.0

Back up the panel database and keep the previous `.pteroext`. On a test panel, upload the new package through the extension manager and confirm replacement, or run from the panel directory (use the actual uploaded file path):

```sh
php artisan p:extension:install /path/to/dynamic-allocations-0.3.0.pteroext --replace
php artisan p:extension:list
```

The installed extension identifier stays `dynamic-allocations`; an enabled extension stays enabled. If needed, enable it with `php artisan p:extension:enable dynamic-allocations`. Refresh the browser. For rollback, disable the extension or install the previous archive with `--replace`. No server, allocation or egg is changed by installation alone. Saved defaults are stored in existing extension settings; a downgrade does not need to delete them.

## Default ports per egg (since v0.2.0)

In Admin > Dynamic Allocations, select an egg, enter the comma-separated port list in Network, and click **Save current ports as defaults**. This stores a preset for that egg using the panel's native `ExtensionSettings::for(Egg)` API, under the extension's `port_preset` key. There is no additional migration or local browser storage. Root administrators only may read and change these presets through the extension endpoints. Saving validates and normalizes the port list with the same parser used for creation, but does not allocate or reserve any port. Presets are shared by administrators of this panel and apply independently of the node.

Selecting an egg fills its saved ports or clears the port inputs if none exist. **Apply saved defaults** restores the preset after manual edits. **Remove saved defaults** deletes the preset without changing the current form or existing allocations. Manual edits affect only the current server unless explicitly saved. There are no automatically guessed or bundled presets; e.g. enter `80, 443` for the future direct-listen NGINX proxy, or `25565` for Minecraft. The current NGINX v0.1 image does not yet support direct listening on 80/443. Availability still depends on the selected node/IP and native allocation/host state; it is validated at creation, not when saving defaults.

## Network configuration (new in v0.3.0)

Select a node, then choose its IPv4 or `127.0.0.1 (Localhost / internal)` in Bind IP. There is no dependency on existing allocation rows. A literal node IPv4 or a unique DNS IPv4 from the node FQDN is suggested automatically when the node is not marked behind a proxy. DNS is not proof that an address is actually bound on the host: confirm the address before creating servers. If the FQDN is proxied, does not resolve uniquely, or points to a different address, expand **Configure node IPv4 (only if needed)** and save the real host IPv4 once. This is a node-scoped native ExtensionSettings value, not an allocation. Reset removes the override. Localhost remains available even on an empty node. Standard Wings bridge networking maps 127.0.0.1 allocations to its bridge interface, not necessarily a host-loopback-only socket. Check isolation and actual Docker bindings separately.

Enter ports in a single comma-separated field, e.g. `80, 443`. The first is primary, the rest are additional; order is preserved. Allowed: 1–65535, up to 32 ports. Empty entries, duplicates and ranges are rejected. Existing egg presets keep their original storage format and are presented as a single list. Presets and address settings never reserve ports by themselves.

Select owner, egg, image, startup, variables and resources as before. At creation, the backend validates the address against the current node options, creates missing rows and reuses free exact matches. Free rows on other IPs no longer block creation; assigned same-number ports on any IP of the node remain conservative conflicts (Wings bridge/wildcard ambiguity). Native StoreServerRequest and ServerController are then used for server creation. No IPv6, wildcard, existing server port editing or automatic deletion of free allocation rows. Native allocation inventory remains as a diagnostic/compatibility view. No host port scan, no additional node service.

For the NGINX proxy: choose public IPv4, 80 and 443; the NGINX egg itself still needs the separate direct-listen update (the previous egg listens on 18080/18443 and expects PROXY protocol). Check host TCP and UDP listeners and container bindings beforehand; Wings publishes both protocols. No host port scan is performed by the extension. Never give a public privileged port to an untrusted server owner without deliberate approval.

## Failure behavior and tests

Allocation preparation is transactional; a MariaDB named lock serializes extension requests per node. The native action commits the server before calling Wings. This is not one atomic transaction across the panel and Wings. Free allocations may remain after native validation/provision failure; they are intentionally not deleted and can be reused. Other native creation/assignment paths do not honor the extension lock: avoid concurrent provisioning or allocation edits from native UI/API/other extensions. This preview does not guarantee race-free operation in multi-admin installations. A timed-out response is ambiguous: verify native server list and Wings before retrying. No automatic retry or cleanup of data.

Static checks performed locally: PHP syntax, JS syntax, JSON parse and independent port/IP, node-address and preset tests (`php tests/ports.php` and `php tests/presets.php`). No live Panel/SDK/MariaDB/Wings integration, browser rendering, permission verification or official package validation has been run. Test on a disposable Panel copy and node; do not connect a cloned test Panel to production Wings with live credentials. Test: non-admin requests refused; create with a fresh high port and a free existing port; invalid/duplicate ports refused; existing assigned ports untouched; wrong node IP/tag rejected; simultaneous extension requests; rejected creation leaves only free rows; native server details and container mappings; disable extension and verify stock UI. For presets additionally save a preset for egg A, switch to egg B (empty or separate preset), switch back, refresh the browser, manually override ports, reapply and delete the preset. Verify persistence, non-admin denial, unchanged allocation count while saving/deleting, and that invalid or duplicate ports are refused. For v0.3.0 additionally run `php tests/network.php`; test an empty node, localhost, a unique FQDN address, multiple DNS answers or proxied FQDN with explicit override, comma-list ordering, rejection of malformed lists, reuse of free rows, existing assigned conflicts, and persistence of legacy presets. Then test 80/443 on isolated node, no other host listener, inspect `docker ps`, confirm HTTP and TLS independently. If a test fails, stop and inspect panel logs, rather than repeatedly pressing create.

## Upstream sources
- https://docs.pterodactyl.io/v2/extensions
- https://docs.pterodactyl.io/v2/extensions/frontend
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Http/Requests/Api/Admin/Servers/StoreServerRequest.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Actions/Servers/CreateServer.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Http/Controllers/Api/Admin/Servers/ServerController.php

- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Services/Extensions/ExtensionSettings.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Console/Commands/Extensions/InstallCommand.php
