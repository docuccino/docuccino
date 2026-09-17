// check-icons.mjs — fail the build when a page names an icon Starlight doesn't have.
//
// `<Card icon="…">` renders an EMPTY `<svg>` for a name that isn't in Starlight's set: no error, no
// warning, just a blank chip that nobody notices in review. Two shipped that way ("php" and
// "laravel", which read like they should exist and don't), so the name is checked against the set
// itself rather than against a list kept here — the set is Starlight's, and it grows every release.
//
// Runs as part of the `prebuild` npm hook.
//
//   node scripts/check-icons.mjs

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const website = resolve(here, '..');

// Starlight declares its icons as two object literals with one quoted key per line. `Icons` is the
// union of them, and `StarlightIcon` — the prop's type — is `keyof typeof Icons`.
const iconSources = [
	'node_modules/@astrojs/starlight/components-internals/Icons.ts',
	'node_modules/@astrojs/starlight/user-components/file-tree-icons.ts',
];

const names = new Set();
for (const source of iconSources) {
	const text = readFileSync(join(website, source), 'utf8');
	for (const match of text.matchAll(/^\t'?([A-Za-z0-9:_+.-]+)'?:/gm)) names.add(match[1]);
}

// A scan that matches nothing must fail rather than pass: if a Starlight release changes how those
// files are written, the parse goes quiet and every name would look valid.
const sentinels = ['right-arrow', 'github', 'seti:git'];
const missing = sentinels.filter((name) => !names.has(name));
if (missing.length > 0 || names.size < 100) {
	console.error(
		`check-icons: read ${names.size} icon names from Starlight, which is not what its icon set ` +
			`looks like${missing.length > 0 ? ` (missing: ${missing.join(', ')})` : ''}. The files ` +
			`this reads have probably moved:\n  ${iconSources.join('\n  ')}`
	);
	process.exit(1);
}

const docs = resolve(website, 'src/content/docs');
const pages = [];
const walk = (dir) => {
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const path = join(dir, entry.name);
		if (entry.isDirectory()) walk(path);
		else if (entry.name.endsWith('.md') || entry.name.endsWith('.mdx')) pages.push(path);
	}
};
walk(docs);

const unknown = [];
let used = 0;
for (const page of pages) {
	const text = readFileSync(page, 'utf8');
	// `icon="x"` on a component, and `icon: x` in the frontmatter hero actions.
	for (const match of text.matchAll(/icon[=:]\s*['"]([^'"]+)['"]/g)) {
		used++;
		if (!names.has(match[1])) {
			unknown.push(`${page.slice(website.length + 1)}: ${match[1]}`);
		}
	}
}

if (used === 0) {
	console.error('check-icons: found no icons at all in src/content/docs — this scan is broken.');
	process.exit(1);
}

if (unknown.length > 0) {
	console.error(
		`check-icons: ${unknown.length} page(s) name an icon Starlight doesn't have — each renders ` +
			`as an empty chip:\n  ${unknown.join('\n  ')}\n` +
			'Pick a name from @astrojs/starlight/components-internals/Icons.ts (or the seti:* set in ' +
			'user-components/file-tree-icons.ts).'
	);
	process.exit(1);
}

console.log(`check-icons: ${used} icon reference(s) across ${pages.length} pages, all known.`);
