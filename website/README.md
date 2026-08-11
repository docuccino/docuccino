# Docuccino documentation site

The source for **docs.docuccino.app** (and **spec.docuccino.app**), built with
[Astro](https://astro.build) + [Starlight](https://starlight.astro.build).

This is a plain Node project living at the repo root — it is **not** a Composer package and is not
part of the `docuccino/*` subtree splits. `node_modules/`, `dist/`, and `.astro/` are gitignored;
`package-lock.json` is committed for reproducible CI installs.

## Develop

```bash
cd website
npm ci          # install pinned deps (npm install for a fresh lockfile)
npm run dev     # local dev server with HMR
```

## Build

```bash
cd website
npm run build   # static site → website/dist/
```

`npm run build` runs a `prebuild` hook (`node scripts/sync-schema.mjs --check`) that fails if the
published UIR schema under `public/uir/` has drifted from the canonical `spec/uir/` at the repo root.

## Machine-readable output

Two Starlight plugins publish the docs for readers that aren't browsers — an AI assistant asked about
Docuccino, or anything that would rather parse Markdown than HTML. Both run at build time only:

| Plugin | Produces | Notes |
| --- | --- | --- |
| [`starlight-llms-txt`](https://delucis.github.io/starlight-llms-txt/) | `/llms.txt`, `/llms-full.txt`, `/llms-small.txt` | `llms.txt` is the index; `-full` is every page concatenated, `-small` the same with the comparison pages and spec-hosting detail dropped for tight context windows. Its `description`, `details`, `promote` and `exclude` options are set in `astro.config.mjs`. |
| [`starlight-md-txt`](https://max-ostapenko.github.io/starlight-md-txt/) | A `.md` twin beside every page (`/laravel/getting-started.md`) | The rule is "page URL + `.md`". GitHub Pages serves these as `text/markdown`, so a browser downloads rather than renders them; switch the plugin's `format` to `'.md.txt'` if you'd rather they display inline. |

The landing page is the one exception to the URL rule: the plugin routes twins as `[...slug].md`, and
the index slug is empty, so its twin is the hidden `/.md`. A small `astro:build:done` integration in
`astro.config.mjs` copies it to `/index.md` too.

Both are regenerated on every build, so neither needs committing — like the rest of `dist/`.

## Deploy

The site is **static** — no adapter, no server. `.github/workflows/deploy.yml` builds it with
`withastro/action` on every push to `main` that touches `website/**` or `spec/uir/**`, and publishes
it to GitHub Pages at **docs.docuccino.app**.

The custom domain comes from the committed `public/CNAME`, which Astro copies into `dist/` on each
build — delete that file and the next deploy drops the domain. The DNS side is a single record:
`docs` `CNAME` → `docuccino.github.io.`

`.github/workflows/website.yml` builds the site on pull requests without deploying, so a broken
build is caught before it can reach `main`.

## UIR schema hosting

The UIR JSON Schemas are served as static files at their exact `$id` URLs (e.g.
`https://spec.docuccino.app/uir/1.0/schema.json`).

> **`spec.docuccino.app` is not served by this site's deploy.** A GitHub Pages site carries exactly
> one custom domain, which is `docs.docuccino.app`. The schemas are served from their own Pages site
> instead: the repo-root `spec/` directory is subtree-split to `docuccino/spec` (see
> `.github/workflows/split.yml`), which carries the `spec.docuccino.app` domain.
>
> `public/uir/` here is a second copy of the same bytes, kept because the drift guard below and
> ci.yml's `schema-copies` job compare against it. It also means the schema stays reachable at
> `https://docs.docuccino.app/uir/1.0/schema.json`.

Keep the published copy in sync with the source of truth:

```bash
npm run sync-schema   # copy spec/uir/<ver>/schema.json -> public/uir/<ver>/schema.json
```

## Branding

- Site logos: `src/assets/logo-light.svg` (light theme) / `src/assets/logo.svg` (dark theme).
- Favicon: `public/icon.svg`.
- Hero: `src/assets/hero.svg` — the animated brand mark, with a self-contained
  `@media (prefers-reduced-motion: reduce)` guard that stills the animation for users who ask for
  reduced motion.

All are copies of the assets in the repo-root `art/` directory; refresh them there and re-copy if
the brand changes.

## Content layout

```
src/content/docs/
├── index.mdx                 # splash landing page
├── laravel/                  # everything framework-specific
│   ├── getting-started/      # install, first export
│   ├── documenting/          # one page per concern (requests, responses, errors, …)
│   ├── packages/             # one page per supported ecosystem package
│   ├── guides/               # how it works, production, viewer, multiple documents, …
│   └── reference/            # configuration, commands, attributes
├── extending/                # writing an integration (framework-agnostic)
├── guides/                   # the comparison pages (vs Scramble, vs Scribe)
└── uir/                      # UIR format + spec hosting
```

The `laravel/` scoping is deliberate and invisible to readers — framework-specific pages live under
it so a second framework can be added without moving a URL. `STYLE.md` has the reasoning.

The navigation is defined in `astro.config.mjs`. Content follows the editorial direction in
[`STYLE.md`](./STYLE.md): written for developers adopting Docuccino, sourced from the real behavior
of the packages, with realistic domain examples.
