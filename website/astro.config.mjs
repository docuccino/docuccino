// @ts-check
import { copyFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightLlmsTxt from 'starlight-llms-txt';
import starlightMdTxt from 'starlight-md-txt';

// starlight-md-txt routes its Markdown twins as `[...slug].md`, so the landing page — whose slug is
// empty — lands on the hidden path `/.md`. Copy it to the conventional `/index.md` as well, so the
// root page is reachable by the name a reader (or a crawler) would actually try. Every other page
// already follows "page URL + .md".
const indexMarkdownAlias = {
	name: 'docuccino-index-markdown-alias',
	hooks: {
		'astro:build:done': ({ dir }) => {
			const hidden = fileURLToPath(new URL('./.md', dir));
			if (existsSync(hidden)) {
				copyFileSync(hidden, fileURLToPath(new URL('./index.md', dir)));
			}
		},
	},
};

// https://astro.build/config
export default defineConfig({
	// Static output (Astro's default — no `output` or `adapter` key). The site is a pure content
	// site: there are no `src/pages`, no endpoints and no request-time APIs, so nothing here needs a
	// server. GitHub Pages serves the built `dist/` directly.
	//
	// No `base`: the site is served from the root of its own custom domain (see public/CNAME), so
	// paths stay identical to the URLs already published in every package README. A project-page
	// deploy would need `base: '/docuccino'` and would move every URL.
	site: 'https://docs.docuccino.app',
	// The bundled Scalar viewer on the landing page is a large, intentional client chunk; lift the
	// size-warning threshold so it doesn't flag on every build.
	vite: {
		build: { chunkSizeWarningLimit: 3000 },
	},
	integrations: [
		indexMarkdownAlias,
		starlight({
			title: 'Docuccino',
			description:
				'UIR-based API documentation generator for Laravel: deep type inference, deterministic output, semantic diffing and a bundled API viewer.',
			// Machine-readable copies of the docs, for readers who arrive as an AI assistant rather
			// than in a browser: llms-txt builds the /llms*.txt digests, md-txt writes a .md twin
			// beside every page. Both are build-time only — no runtime, no request-time work.
			plugins: [
				starlightLlmsTxt({
					description:
						'Docuccino is an open-source (MIT) API documentation generator for Laravel. It compiles an application into a UIR (Universal Intermediate Representation — an OpenAPI-3.2-shaped, deterministic, identity-carrying JSON document) and emits OpenAPI 3.2/3.1/3.0, with semantic diffing and a bundled API viewer (Scalar or Redoc).',
					details: [
						'## Key facts',
						'',
						'- Install: `composer require docuccino/laravel` plus `composer require --dev docuccino/inference-phpstan` (the analysis engine is a dev dependency; the adapter degrades to no inference without it), then `php artisan docuccino:install`.',
						'- Requirements: PHP 8.3+, Laravel 12 or 13.',
						'- Commands: `docuccino:install`, `docuccino:export`, `docuccino:validate`, `docuccino:diff`, `docuccino:cache`, `docuccino:clear`, `docuccino:watch`, `docuccino:coverage`, `docuccino:explain`.',
						'- `docuccino:install` is the first-run command: it publishes `config/docuccino.php` (never overwriting an existing one without `--force`), reports how many of the application\'s routes each document actually matches and which prefixes they sit under when none do, says whether the analysis engine is installed, and offers a first export. It is idempotent and works non-interactively.',
						'- Config lives in one published file, `config/docuccino.php`, organized around named `documents`.',
						'- The document build never executes application code — it reads types with an embedded PHPStan/Larastan engine, and reads committed files for anything else. Real response payloads reach the document only as recordings your own test suite wrote.',
						'- Output is byte-deterministic, so the exported document is meant to be committed and diffed.',
						'- Contract testing: `Docuccino\\Laravel\\Testing\\AssertsApiContract` asserts a Laravel test suite\'s real requests and responses against the generated UIR, validates documented examples, and gates breaking changes and artifact staleness. Endpoint coverage is a post-run step instead of an assertion — the suite logs what each process exercised and `docuccino:coverage` merges those logs and gates, so it works under parallel runners and sharded CI where no single test process can see the whole suite. `ApiContract::record()` writes those responses to committed files (keyed by operation id, credentials redacted) that the build publishes as examples at the integration precedence layer.',
						'- Precedence, lowest to highest: fallback, inference, integration, docblock, attribute, overlay, config. Higher layers win field by field.',
						'- `docuccino:explain <route>` reads the provenance trail back for one operation: which layer won each field, what it shadowed, the file:line it came from, and a `→` line naming what to change to override it (the specific attribute where one writes that field, else the generic truth that an overlay outranks it). It accepts a route name, an operation id or a URI (with an optional leading method), `--field=<name>` prints one field with every value in full, it lists every match when a query is ambiguous (exit 2), and it needs no export flags — it builds the document itself, where the trail is always complete.',
					].join('\n'),
					// Pages ordered for a reader starting from zero.
					promote: ['index*', 'laravel/getting-started*', 'laravel/guides/how-it-works*'],
					// The small variant is for tight context windows: keep the task-shaped pages, drop
					// the migration/comparison material, the spec-hosting detail and the changelog.
					exclude: ['guides/vs-*', 'uir/hosting*', 'changelog*'],
				}),
				starlightMdTxt(),
			],
			logo: {
				// The task-specified mapping: light theme → logo-light.svg, dark theme → logo.svg.
				light: './src/assets/logo-light.svg',
				dark: './src/assets/logo.svg',
				replacesTitle: true,
				alt: 'Docuccino',
			},
			favicon: '/icon.svg',
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/docuccino/docuccino' },
			],
			editLink: {
				baseUrl: 'https://github.com/docuccino/docuccino/edit/main/website/',
			},
			// The sidebar is deliberate: every label and every position is set here, nothing is
			// autogenerated, so the Laravel content path-scoping (laravel/*) stays invisible to
			// readers and package-named entries mirror the ecosystems developers already know. When a
			// second framework ships, this becomes a starlight-sidebar-topics switcher without moving
			// a single URL.
			//
			// Order follows the adopter's lifecycle — install, run it, understand it, document, look
			// up, extend — and Starlight derives each page's prev/next link from it, so the order is
			// also the reading path. Competitive material stays out of that path (website/STYLE.md).
			sidebar: [
				{
					label: 'Getting started',
					items: [
						{ label: 'Installation', slug: 'laravel/getting-started' },
						{ label: 'Your first export', slug: 'laravel/getting-started/first-export' },
						// The mental model belongs to onboarding, not to the guides: it answers the
						// questions a first export raises (what did it read? what wins?).
						{ label: 'How it works', slug: 'laravel/guides/how-it-works' },
					],
				},
				{
					label: 'Documenting your API',
					items: [
						{ label: 'Overview', slug: 'laravel/documenting' },
						{ label: 'Requests', slug: 'laravel/documenting/requests' },
						{ label: 'Responses', slug: 'laravel/documenting/responses' },
						{ label: 'Resources, models & enums', slug: 'laravel/documenting/schemas' },
						{ label: 'Error responses', slug: 'laravel/documenting/errors' },
						{ label: 'Example payloads', slug: 'laravel/documenting/examples' },
						{ label: 'Authentication', slug: 'laravel/documenting/authentication' },
						{ label: 'Rate limiting', slug: 'laravel/documenting/rate-limiting' },
						{ label: 'Webhooks', slug: 'laravel/documenting/webhooks' },
					],
				},
				{
					label: 'Package support',
					items: [
						{ label: 'Overview', slug: 'laravel/packages' },
						{ label: 'Spatie Data', slug: 'laravel/packages/spatie-data' },
						{ label: 'Spatie Query Builder', slug: 'laravel/packages/query-builder' },
						{ label: 'Laravel Actions', slug: 'laravel/packages/laravel-actions' },
						{ label: 'JSON:API Resources (TiMacDonald)', slug: 'laravel/packages/timacdonald-json-api' },
						{ label: 'Spatie JSON API Paginate', slug: 'laravel/packages/json-api-paginate' },
						{
							label: 'Spatie Laravel Permission',
							slug: 'laravel/packages/laravel-permission',
							// The one integration that isn't automatic — say so where it's scanned, not
							// only on the page.
							badge: { text: 'Opt-in', variant: 'note' },
						},
					],
				},
				{
					// Ordered by when a reader needs them: fix the output, look at it, split it, add
					// prose, hold it to your tests, version it, ship it, make it fast, and — when it
					// goes wrong — troubleshoot. Versioning follows contract testing because the
					// per-version check is built out of those assertions. Build speed follows
					// production because its CI recipe extends the job that page builds.
					label: 'Guides',
					items: [
						{ label: 'Customizing the output', slug: 'laravel/guides/customizing-output' },
						{ label: 'The viewer', slug: 'laravel/guides/viewer' },
						{ label: 'Multiple documents', slug: 'laravel/guides/multiple-documents' },
						{ label: 'Adding your own pages', slug: 'laravel/guides/narrative-content' },
						{ label: 'Contract testing', slug: 'laravel/guides/contract-testing' },
						{ label: 'API versioning', slug: 'laravel/guides/api-versioning' },
						{ label: 'Deploying to production', slug: 'laravel/guides/production' },
						{ label: 'Speeding up builds', slug: 'laravel/guides/speeding-up-builds' },
						{ label: 'Troubleshooting', slug: 'laravel/guides/troubleshooting' },
					],
				},
				{
					// The group already says "reference" — the entries name the surface.
					label: 'Reference',
					items: [
						{ label: 'Configuration', slug: 'laravel/reference/configuration' },
						{ label: 'Commands', slug: 'laravel/reference/commands' },
						{ label: 'Attributes', slug: 'laravel/reference/attributes' },
						{ label: 'Diagnostics', slug: 'laravel/reference/diagnostics' },
					],
				},
				{
					label: 'Extending',
					items: [{ label: 'Writing an integration', slug: 'extending/extension-authoring' }],
				},
				{
					// These pages carry the migration walkthroughs too, so the label says so — it's the
					// word a reader arriving from another generator scans for.
					label: 'Comparisons & migration',
					items: [
						{ label: 'Docuccino vs Scramble', slug: 'guides/vs-scramble' },
						{ label: 'Docuccino vs Scribe', slug: 'guides/vs-scribe' },
					],
				},
				{
					label: 'UIR spec',
					items: [
						{ label: 'Format overview', slug: 'uir' },
						{ label: 'Spec hosting', slug: 'uir/hosting' },
					],
				},
				// Generated from the commit history by tools/changelog.php — one page, not a section.
				{ label: 'Changelog', slug: 'changelog' },
			],
		}),
	],
});
