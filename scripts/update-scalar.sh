#!/usr/bin/env bash
#
# update-scalar.sh — refresh the vendored Scalar API-reference standalone bundle.
#
# Docuccino's bundled viewer (packages/laravel/src/Viewer/ScalarViewer.php) serves the
# Scalar standalone browser build LOCALLY from its own asset route, so the docs page never
# reaches a runtime CDN by default. That build is vendored at:
#
#     packages/laravel/resources/js/scalar.standalone.js
#
# It is the pristine, byte-for-byte `dist/browser/standalone.js` from the npm package
# `@scalar/api-reference` (the same file jsDelivr serves for the CDN opt-in). It is a single,
# self-contained ~3.6 MB minified file — no code-split chunks — and is marked non-diffable /
# vendored in packages/laravel/.gitattributes.
#
# Currently vendored: @scalar/api-reference@1.64.0
#
# To pin a different version, pass it as the first argument, e.g.:
#     scripts/update-scalar.sh 1.64.0
#
# After running, commit the changed bundle as an ISOLATED commit and update the version
# recorded above and in website/src/content/docs (Viewer page) so the docs stay honest.
#
set -euo pipefail

VERSION="${1:-1.64.0}"
PKG="@scalar/api-reference@${VERSION}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${REPO_ROOT}/packages/laravel/resources/js/scalar.standalone.js"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

echo "==> Fetching ${PKG} via npm pack…"
cd "${WORK}"
TARBALL="$(npm pack "${PKG}" 2>/dev/null | tail -1)"

echo "==> Extracting dist/browser/standalone.js…"
tar xzf "${TARBALL}" package/dist/browser/standalone.js

SRC="${WORK}/package/dist/browser/standalone.js"
if ! grep -q "api-reference" "${SRC}"; then
  echo "ERROR: extracted bundle does not reference the 'api-reference' element id —" >&2
  echo "       Scalar's standalone entry point may have moved; aborting." >&2
  exit 1
fi
if grep -qE "chunks/[A-Za-z0-9._-]+\.js" "${SRC}"; then
  echo "ERROR: extracted bundle imports code-split chunks — it is no longer a single" >&2
  echo "       self-contained file. Re-evaluate the vendoring strategy." >&2
  exit 1
fi

cp "${SRC}" "${DEST}"

echo "==> Vendored @scalar/api-reference@${VERSION}"
echo "    -> ${DEST}"
echo "    size:   $(du -h "${DEST}" | cut -f1)"
echo "    sha256: $(shasum -a 256 "${DEST}" | cut -d' ' -f1)"
echo
echo "Next: run the viewer feature tests and commit the bundle in an isolated commit:"
echo "    vendor/bin/pest --filter=Viewer"
echo "    git add packages/laravel/resources/js/scalar.standalone.js"
