#!/usr/bin/env bash
#
# Materialise Livewire's JS bundle at the public path its <script> tag requests.
#
# WHY THIS IS NEEDED
# ------------------
# Livewire serves livewire.min.js from a *dynamic route* under a hashed prefix
# (e.g. /livewire-766def08/livewire.min.js), not from a file on disk. The
# CloudPanel nginx vhost in front of this app has a regex location matching any
# `*.js` request:
#
#   location ~* ^.+\.(css|js|...)$ { ... }
#
# nginx evaluates regex locations before the PHP fallback, so that request is
# served straight from the docroot, misses, and returns 404. Livewire and Alpine
# then never load, and every interactive control on the site silently dies:
# mobile menu, filter drawers, product gallery, tabs, the whole chat thread.
#
# Copying the bundle onto disk sidesteps routing entirely -- nginx serves static
# files from the docroot correctly, so the very location that broke it now
# fixes it.
#
# The prefix is read from the registered route rather than hardcoded, so a
# Livewire upgrade or anything else that shifts the hash is picked up
# automatically. Stale livewire-* directories are pruned.
#
# Run from the application root, after `composer install`, on every deploy.

set -euo pipefail

cd "$(dirname "$0")/.."

DIST="vendor/livewire/livewire/dist"

if [ ! -d "$DIST" ]; then
    echo "error: $DIST not found -- run composer install first" >&2
    exit 1
fi

# Ask the router what URI the script tag will request.
# Which bundle Livewire serves depends on the environment: a debug build
# registers `livewire.js`, production registers `livewire.min.js`. Match the
# main bundle by exact basename so neither the .map nor the .csp variants win.
URI=$(php artisan route:list --path=livewire --json 2>/dev/null \
    | php -r '$j = json_decode(stream_get_contents(STDIN), true) ?: [];
              foreach ($j as $r) {
                  $u = $r["uri"] ?? "";
                  if (in_array(basename($u), ["livewire.min.js", "livewire.js"], true)) { echo $u; exit; }
              }')

if [ -z "$URI" ]; then
    echo "error: could not resolve the Livewire livewire.min.js route" >&2
    exit 1
fi

PREFIX=$(dirname "$URI")
BUNDLE=$(basename "$URI")

if [ "$PREFIX" = "." ] || [ -z "$PREFIX" ]; then
    echo "error: unexpected Livewire asset URI: $URI" >&2
    exit 1
fi

# Drop directories from a previous hash so they cannot be served stale.
for old in public/livewire-*; do
    [ -d "$old" ] || continue
    [ "$(basename "$old")" = "$(basename "$PREFIX")" ] && continue
    rm -rf "$old"
    echo "pruned stale $old"
done

if [ ! -f "$DIST/$BUNDLE" ]; then
    echo "error: $DIST/$BUNDLE does not exist" >&2
    exit 1
fi

mkdir -p "public/$PREFIX"
cp "$DIST/$BUNDLE" "public/$PREFIX/"
[ -f "$DIST/$BUNDLE.map" ] && cp "$DIST/$BUNDLE.map" "public/$PREFIX/"

echo "published $BUNDLE to public/$PREFIX/"
