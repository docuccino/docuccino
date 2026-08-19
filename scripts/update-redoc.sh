#!/usr/bin/env bash
#
# update-redoc.sh — refresh the vendored Redoc standalone bundle.
#
# The `redoc` viewer driver (php/laravel/src/Viewer/RedocViewer.php) serves the Redoc
# standalone browser build LOCALLY from its own asset route, exactly like the Scalar default, so
# picking it never makes the docs page depend on a CDN. That build is vendored at:
#
#     php/laravel/resources/js/redoc.standalone.js
#     php/laravel/resources/js/redoc.standalone.js.LICENSE.txt
#
# It is the pristine, byte-for-byte `bundles/redoc.standalone.js` from the npm package `redoc`
# (the same file jsDelivr serves for the CDN opt-in), plus the license notice the bundle's header
# points at. It is a single, self-contained ~1.1 MB minified file — its search web worker is
# inlined as a Blob rather than fetched — and is marked non-diffable / vendored in
# php/laravel/.gitattributes.
#
# Currently vendored: redoc@2.5.3
#
# To pin a different version, pass it as the first argument, e.g.:
#     scripts/update-redoc.sh 2.5.3
#
# After running, commit the changed bundle as an ISOLATED commit and update the version
# recorded above and in website/src/content/docs (Viewer page) so the docs stay honest.
#
set -euo pipefail

VERSION="${1:-2.5.3}"
PKG="redoc@${VERSION}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${REPO_ROOT}/php/laravel/resources/js/redoc.standalone.js"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

echo "==> Fetching ${PKG} via npm pack…"
cd "${WORK}"
TARBALL="$(npm pack "${PKG}" 2>/dev/null | tail -1)"

echo "==> Extracting bundles/redoc.standalone.js…"
tar xzf "${TARBALL}" package/bundles/redoc.standalone.js package/bundles/redoc.standalone.js.LICENSE.txt

SRC="${WORK}/package/bundles/redoc.standalone.js"
if ! grep -q "redoc" "${SRC}"; then
  echo "ERROR: extracted bundle does not mention the 'redoc' element —" >&2
  echo "       Redoc's standalone entry point may have moved; aborting." >&2
  exit 1
fi
# The search index runs in a web worker. The standalone build inlines it as a Blob; a build that
# fetched it as a separate chunk would need a second asset route and break the offline guarantee.
if ! grep -q "createObjectURL" "${SRC}"; then
  echo "ERROR: extracted bundle no longer inlines its web worker — it would fetch a chunk at" >&2
  echo "       runtime. Re-evaluate the vendoring strategy." >&2
  exit 1
fi

cp "${SRC}" "${DEST}"
cp "${SRC}.LICENSE.txt" "${DEST}.LICENSE.txt"

echo "==> Vendored redoc@${VERSION}"
echo "    -> ${DEST}"
echo "    size:   $(du -h "${DEST}" | cut -f1)"
echo "    sha256: $(shasum -a 256 "${DEST}" | cut -d' ' -f1)"
echo
echo "Next: run the viewer feature tests and commit the bundle in an isolated commit:"
echo "    vendor/bin/pest --parallel --filter=Viewer"
echo "    git add php/laravel/resources/js/redoc.standalone.js*"
