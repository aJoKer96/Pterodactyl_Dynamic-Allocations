# Dynamic Allocations v0.1.0 — preview

Native Pterodactyl Panel extension for **2.0.0-beta.3 / SDK 2.0.0-beta.6**. No Wings patch, host service, database migration or core file modification. Admin > Dynamic Allocations offers a separate creation screen; the stock creation screen and node allocation inventory are unchanged. The SDK in beta.3 cannot replace the native allocation box. The extension is intended for a single administrator on a test panel first.

## Installation

Upload the accompanying `.pteroext` (ZIP with the runtime manifest at its root) through Admin > Extensions > Install. Enable only on the test panel, or use CLI from `/var/www/pterodactyl`:

```sh
php artisan p:extension:install /path/to/dynamic-allocations-0.1.0.pteroext
chown -R www-data:www-data extensions public/assets/extensions
php artisan p:extension:enable dynamic-allocations
php artisan p:extension:list
```

Alternatively the source directory can be checked and packaged using the Panel's own `php artisan p:extension:pack /path/to/dynamic-allocations --output=/tmp/dynamic-allocations.pteroext`. The frontend is a plain JavaScript ESM module using the SDK and React from the panel import map; no local npm build necessary. Disable with `php artisan p:extension:disable dynamic-allocations`. If extension loading causes problems, set `PTERODACTYL_EXTENSIONS_ENABLED=false` in `.env`, clear configuration cache and reload PHP-FPM. Existing allocations and servers remain intact.

## Use and constraints

Select owner, egg, image, startup, variables, resources, node, one existing node IPv4 and a primary port (1–65535). Additional ports are comma or whitespace separated, up to 32 total. The extension creates missing allocation rows and reuses free exact matches, then resolves the native StoreServerRequest and invokes the native admin ServerController. This keeps native validation, tags, activity and Wings flow. IP must already be registered in the node allocation inventory; IPv6/wildcard not supported. Same-number allocation on another IP of the same node is deliberately rejected (including free entries). No port ranges, existing server edits, automatic port release, or egg port defaults in this version. The underlying allocation data stays native.

For the NGINX proxy: choose public IPv4, 80 and 443; the NGINX egg itself still needs the separate direct-listen update (the previous egg listens on 18080/18443 and expects PROXY protocol). Check host TCP and UDP listeners and container bindings beforehand; Wings publishes both protocols. No host port scan is performed by the extension. Never give a public privileged port to an untrusted server owner without deliberate approval.

## Failure behavior and tests

Allocation preparation is transactional; a MariaDB named lock serializes extension requests per node. The native action commits the server before calling Wings. This is not one atomic transaction across the panel and Wings. Free allocations may remain after native validation/provision failure; they are intentionally not deleted and can be reused. Other native creation/assignment paths do not honor the extension lock: avoid concurrent provisioning or allocation edits from native UI/API/other extensions. This preview does not guarantee race-free operation in multi-admin installations. A timed-out response is ambiguous: verify native server list and Wings before retrying. No automatic retry or cleanup of data.

Static checks performed locally: PHP syntax, JS syntax, JSON parse and independent port/IP parser tests (`php tests/ports.php`). No live Panel/SDK/MariaDB/Wings integration, browser rendering, permission verification or official package validation has been run. Test on a disposable Panel copy and node; do not connect a cloned test Panel to production Wings with live credentials. Test: non-admin requests refused; create with a fresh high port and a free existing port; invalid/duplicate ports refused; existing assigned ports untouched; wrong node IP/tag rejected; simultaneous extension requests; rejected creation leaves only free rows; native server details and container mappings; disable extension and verify stock UI. Then test 80/443 on isolated node, no other host listener, inspect `docker ps`, confirm HTTP and TLS independently. If a test fails, stop and inspect panel logs, rather than repeatedly pressing create.

## Upstream sources
- https://docs.pterodactyl.io/v2/extensions
- https://docs.pterodactyl.io/v2/extensions/frontend
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Http/Requests/Api/Admin/Servers/StoreServerRequest.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Actions/Servers/CreateServer.php
- https://github.com/pterodactyl/panel/blob/v2.0.0-beta.3/app/Http/Controllers/Api/Admin/Servers/ServerController.php
