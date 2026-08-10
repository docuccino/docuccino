// sync-schema.mjs — keep website/public/uir/<ver>/schema.json byte-identical to the
// canonical UIR JSON Schema in the repo's spec/ directory.
//
// spec.docuccino.app serves the schema as a static file at its exact `$id` URL
// (https://spec.docuccino.app/uir/1.0/schema.json) straight out of public/, so the published copy is
// COMMITTED — the site never reaches outside its own directory at build or runtime — and MUST match
// the source of truth in spec/uir/1.0/schema.json.
//
//   node scripts/sync-schema.mjs           # copy spec/ -> public/ (run to refresh)
//   node scripts/sync-schema.mjs --check   # fail (exit 1) if the copies have drifted
//
// The `--check` form runs automatically as the `prebuild` npm hook. TWO environments run that build:
//
//   * the monorepo (spec/ present) — full drift guard, so a stale copy fails the build instead of
//     silently shipping;
//   * a standalone checkout of website/ alone (no ../spec) — the drift guard has nothing to compare
//     against, so it verifies the committed copy is present and parseable and moves on. The GitHub
//     Pages deploy checks out the whole monorepo and so gets the full guard; this branch exists for
//     anyone building the site on its own.
//
// The guard's real home is therefore monorepo CI (.github/workflows/ci.yml runs this --check on every
// push and PR, unfiltered), NOT the deploy: drift can never ship green just because a deploy had
// nothing to check.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..', '..');
const specRoot = resolve(repoRoot, 'spec', 'uir');

// Every UIR spec version we publish. Add a row when a new major.minor ships.
const versions = ['1.0'];

const check = process.argv.includes('--check');
const published = (version) => resolve(here, '..', 'public', 'uir', version, 'schema.json');

// No monorepo spec/ alongside us: this is a standalone deploy of the website. Verify what we ship
// rather than pretending to compare it with a source of truth that is not in this checkout.
if (!existsSync(specRoot)) {
  if (!check) {
    console.error(
      `Cannot sync: ${specRoot} does not exist (this is a standalone website checkout).\n` +
        `Run \`npm run sync-schema\` from a full monorepo checkout instead.`,
    );
    process.exit(1);
  }

  let missing = false;

  for (const version of versions) {
    const target = published(version);
    try {
      JSON.parse(readFileSync(target, 'utf8'));
      console.log(
        `UIR schema ${version}: standalone build: using committed schema (drift check skipped — runs in monorepo CI).`,
      );
    } catch (error) {
      console.error(
        `UIR schema ${version}: committed copy at ${target} is missing or not valid JSON (${error.message}).\n` +
          `The site serves this file at its \`$id\` URL, so it must be committed — run \`npm run sync-schema\` ` +
          `in a monorepo checkout and commit the result.`,
      );
      missing = true;
    }
  }

  process.exit(missing ? 1 : 0);
}

let drift = false;

for (const version of versions) {
  const source = resolve(specRoot, version, 'schema.json');
  const target = published(version);

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
