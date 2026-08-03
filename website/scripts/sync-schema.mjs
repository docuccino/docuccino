// sync-schema.mjs — keep website/public/uir/<ver>/schema.json byte-identical to the
// canonical UIR JSON Schema in the repo's spec/ directory.
//
// spec.docuccino.app serves the schema as a static file at its exact `$id` URL
// (https://spec.docuccino.app/uir/1.0/schema.json), so the published copy MUST match the
// source of truth in spec/uir/1.0/schema.json.
//
//   node scripts/sync-schema.mjs           # copy spec/ -> public/ (run to refresh)
//   node scripts/sync-schema.mjs --check   # fail (exit 1) if the copies have drifted
//
// The `--check` form runs automatically as the `prebuild` npm hook, so a stale copy fails
// the CI website build instead of silently shipping.

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..', '..');

// Every UIR spec version we publish. Add a row when a new major.minor ships.
const versions = ['1.0'];

const check = process.argv.includes('--check');
let drift = false;

for (const version of versions) {
  const source = resolve(repoRoot, 'spec', 'uir', version, 'schema.json');
  const target = resolve(here, '..', 'public', 'uir', version, 'schema.json');

  const src = readFileSync(source, 'utf8');

  if (check) {
    let dst = null;
    try {
      dst = readFileSync(target, 'utf8');
    } catch {
      /* missing target counts as drift */
    }
    if (dst !== src) {
      console.error(
        `UIR schema drift: ${target} is out of sync with ${source}.\n` +
          `Run \`npm run sync-schema\` and commit the result.`,
      );
      drift = true;
    } else {
      console.log(`UIR schema ${version}: in sync.`);
    }
    continue;
  }

  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, src);
  console.log(`UIR schema ${version}: copied spec/ -> public/.`);
}

if (drift) {
  process.exit(1);
}
