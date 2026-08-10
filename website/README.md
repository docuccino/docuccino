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
> one custom domain, which is `docs.docuccino.app`. Publishing `spec/` to its own Pages site is a
> separate task; until it lands, the schema is reachable at
> `https://docs.docuccino.app/uir/1.0/schema.json`. Nothing dereferences the `$id` at runtime —
> `Validator` reads core's package-relative copy — so this affects humans and external tooling only.

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
├── getting-started/          # install, first export
├── reference/                # configuration, commands, attributes
├── integrations/             # one page group per concern (schemas, requests, errors, …)
├── guides/                   # writing an integration, Docuccino vs Scramble
└── uir/                      # UIR format + spec hosting
```

The navigation is defined in `astro.config.mjs`. Content follows the editorial direction in
[`STYLE.md`](./STYLE.md): written for developers adopting Docuccino, sourced from the real behavior
of the packages, with realistic domain examples.
