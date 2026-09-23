// sync-schema.mjs — keep website/public/uir/<ver>/*.json byte-identical to the
// canonical UIR JSON Schemas in the repo's spec/ directory.
//
// spec.docuccino.app serves each schema as a static file at its exact `$id` URL
// (https://spec.docuccino.app/uir/2.0/schema.json) straight out of public/, so the published copy is
// COMMITTED — the site never reaches outside its own directory at build or runtime — and MUST match
// the source of truth in spec/uir/<ver>/.
//
//   node scripts/sync-schema.mjs           # copy spec/ -> public/ (run to refresh)
//   node scripts/sync-schema.mjs --check   # fail (exit 1) if the copies have drifted
//
// Both sides are DISCOVERED rather than listed: every version directory, and every file in it. A
// published `$id` is served forever and the family is two files from 2.0 on (the document schema and
// the extension schema it embeds), so a hand-kept list is how the second one goes stale in
// silence — which is also why the `--check` run in a standalone checkout reads public/ rather than a
// list of what it expected to find there.
//
// The `--check` form runs automatically as the `prebuild` npm hook. TWO environments run that build:
//
//   * the monorepo (spec/ present) — full drift guard, so a stale copy fails the build instead of
//     silently shipping;
//   * a standalone checkout of website/ alone (no ../spec) — the drift guard has nothing to compare
//     against, so it verifies the committed copies are present and parseable and moves on. The GitHub
//     Pages deploy checks out the whole monorepo and so gets the full guard; this branch exists for
//     anyone building the site on its own.
//
// The guard's real home is therefore monorepo CI (.github/workflows/ci.yml runs this --check on every
// push and PR, unfiltered), NOT the deploy: drift can never ship green just because a deploy had
// nothing to check.

import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..', '..');
const specRoot = resolve(repoRoot, 'spec', 'uir');
const publicRoot = resolve(here, '..', 'public', 'uir');

const check = process.argv.includes('--check');

/** The `<version>/<file>` pairs under a uir root, sorted, so both sides are read the same way. */
const schemaFiles = (root) =>
  readdirSync(root, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort()
    .flatMap((version) =>
      readdirSync(resolve(root, version), { withFileTypes: true })
        .filter((entry) => entry.isFile() && entry.name.endsWith('.json'))
        .map((entry) => entry.name)
        .sort()
        .map((file) => `${version}/${file}`),
    );

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

  const published = schemaFiles(publicRoot);

  // A scan that found nothing would report "all present" over an empty set.
  if (published.length === 0) {
    console.error(
      `No UIR schemas committed under ${publicRoot}. The site serves these at their \`$id\` URLs, so ` +
        `they must be committed — run \`npm run sync-schema\` in a monorepo checkout and commit the result.`,
    );
    process.exit(1);
  }

  let missing = false;

  for (const name of published) {
    const target = resolve(publicRoot, name);
    try {
      JSON.parse(readFileSync(target, 'utf8'));
      console.log(
        `UIR schema ${name}: standalone build: using committed schema (drift check skipped — runs in monorepo CI).`,
      );
    } catch (error) {
      console.error(
        `UIR schema ${name}: committed copy at ${target} is not valid JSON (${error.message}).\n` +
          `The site serves this file at its \`$id\` URL, so it must be committed — run \`npm run sync-schema\` ` +
          `in a monorepo checkout and commit the result.`,
      );
      missing = true;
    }
  }

  process.exit(missing ? 1 : 0);
}

const canonical = schemaFiles(specRoot);

if (canonical.length === 0) {
  console.error(`No UIR schemas found under ${specRoot}.`);
  process.exit(1);
}

// A file the site publishes that spec/ no longer has is drift in the other direction: it keeps
// serving a schema nobody authors any more.
const published = existsSync(publicRoot) ? schemaFiles(publicRoot) : [];
const orphans = published.filter((name) => !canonical.includes(name));

let drift = false;

for (const name of orphans) {
  const target = resolve(publicRoot, name);

  if (check) {
    console.error(`UIR schema drift: ${target} is published but has no source under ${specRoot}.`);
    drift = true;
    continue;
  }

  rmSync(target);
  console.log(`UIR schema ${name}: removed (no longer published by spec/).`);
}

for (const name of canonical) {
  const source = resolve(specRoot, name);
  const target = resolve(publicRoot, name);

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
      console.log(`UIR schema ${name}: in sync.`);
    }
    continue;
  }

  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, src);
  console.log(`UIR schema ${name}: copied spec/ -> public/.`);
}

if (drift) {
  process.exit(1);
}
