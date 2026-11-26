#!/usr/bin/env bash
set -euo pipefail
if (( EUID == 0 )); then echo 'Build as a regular user, not root.' >&2; exit 1; fi
[[ $# == 1 ]] || { echo "Usage: $0 /path/to/new-build-checkout" >&2; exit 2; }
checkout=$1
here=$(cd -- "$(dirname -- "$0")" && pwd)
node -e 'const [m,n]=process.versions.node.split(".").map(Number);if(m<22||(m===22&&n<12))throw Error("Node 22.12+ required")'
[[ ! -e "$checkout" ]] || { echo 'Build checkout must not already exist.' >&2; exit 1; }
git clone --depth 1 --branch v2.0.0-beta.3 https://github.com/pterodactyl/panel.git "$checkout"
python3 "$here/apply.py" "$checkout"
cd "$checkout"
npm ci
npm run tsc
npm run build:production
# Deployment requires a successful build and typecheck from this patch.
sha256sum public/assets/manifest.json > .dynamic-allocations-build-ok
printf '\nBuild succeeded: %s\n' "$PWD"
