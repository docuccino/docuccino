# Docuccino Documentation Style Guide (binding — from Tom, 2026-08-03)

**The Laravel documentation is the gold standard.** Take pointers and language from it:
warm, direct, confident prose; short paragraphs; every section earns its place; code
examples that a reader can paste and run.

## Audience & framing

- Written for **new developers adopting Docuccino** — never for ourselves. Zero
  references to internal clients, internal project history, phases, review waves, or agents.
- No internal workings (arch rules, test infrastructure, monorepo mechanics) unless the
  detail adds value to the reader. Prefer outcome framing, e.g.:
  > "Every official integration is built with the same tools we expose to you — you have
  > access to everything we used to build Docuccino."
- **Don't dog on competitors across pages.** Competitive content lives ONLY on dedicated
  per-tool comparison pages (currently "Docuccino vs Scramble" and "Docuccino vs Scribe"),
  each generous in tone and containing that tool's how-to-migrate material. The
  concentration principle is unchanged — no competitor references anywhere outside those
  pages. Everywhere else, Docuccino stands on its own merits.
- **Never state a claim about another tool you cannot verify.** On those pages, every
  sentence and every capability-matrix cell about a competitor has to come from something
  you can actually check — its documentation, its source, its output on a real app. A
  guessed cell is worse than an absent row: it is the first thing a reader of that page
  will test, and being wrong about a competitor costs the whole comparison its credibility
  (and is unfair besides). If you cannot verify it, leave the row out and say the page
  does not cover it.

## Content quality bar

- Every page must contain **the information a developer (or an AI assistant) actually
  needs to do the task** — complete, not a brain dump. The extension-authoring page is
  the canary: it should let someone build a working integration end-to-end without
  reading our source, with a visual structure (annotated code walkthrough, the contract
  surface as a scannable reference, a copyable skeleton).
- Visually pleasing: use Starlight's components (cards, tabs, steps, asides/callouts,
  file-tree) rather than walls of text. Tables for reference material. Diagrams where
  flow matters (pipeline, precedence).
- No filler. If a page or paragraph doesn't help a reader ship something, cut it.
- Config/attribute/command references are exhaustive AND scannable (one row per key/flag,
  behavior + default + example).
- **Don't hardcode a count you would have to remember to update.** "Five artisan commands",
  "all 28 attributes", "78 named rules" go stale silently, and the same number is usually
  repeated on several pages, so the one you update is rarely the only one. Prefer prose that
  carries no number: "every attribute the package ships", "the commands below". Where a
  count genuinely helps the reader it needs a guard that checks it, the way
  `ConfigReferenceSyncTest` and `DiagnosticsReferenceTest` check their pages against the
  code. A count with no guard is a promise to remember.

## Language details

- "you" for the reader; "Docuccino" (never "we" claiming the reader's context).
- Present tense, active voice. British or American spelling — pick one (American, matching
  Laravel docs) and stay consistent.
- Code examples use realistic domain names (invoices, orders, users) — not foo/bar,
  and not our workbench's internal fixtures.

## Page source

- **No invisible markers in page source.** There is no comment syntax that stays invisible
  in everything the site publishes: an HTML comment (`<!-- … -->`) breaks the `.md` twin
  route `starlight-md-txt` serves, because MDX parses it, and the MDX form (`{/* … */}`)
  renders as a visible paragraph and leaks verbatim into `llms-full.txt`. So anything
  machine-readable — a source-of-truth pointer, a marker a guard reads — lives in the tool
  that needs it, not in the page. Write for the reader; keep the bookkeeping out of the
  content.

## Information architecture

Conventions the site follows — keep them when adding pages:

- **`laravel/` path scoping.** Framework-specific pages live under `laravel/`
  (`getting-started`, `documenting`, `packages`, `guides`, `reference`); framework-agnostic
  material stays top-level (`uir/`, `extending/extension-authoring`, the comparison pages).
  The sidebar labels don't expose the path, so the reader experience is unchanged.
- **Topic switcher deferred.** One plain sidebar today. When a second framework ships, add
  `starlight-sidebar-topics` (Laravel / Symfony / UIR) — a config change; no URLs move.
- **Package-named sidebar entries.** Per-package support pages are named as their ecosystems
  are (Spatie Data, Spatie Query Builder, Laravel Actions…), grouped under "Package support",
  so a reader scanning for their package finds it in seconds.
- **Single-concern documenting pages.** Each page under `documenting/` covers exactly one
  concern (requests, responses, schemas, errors, authentication, rate limiting) and is
  complete enough to do that task without reading source.
- **Code → generated-output is the standard proof.** Wherever a page shows PHP the reader
  writes, pair it with a Tabs component: "Your code" beside a curated, trimmed "Generated
  OpenAPI" excerpt derived from real emitter output. It's the product's core wow — keep
  excerpts short and honest.
- **Comparisons are per-tool pages only.** Competitive content lives solely on the
  "Docuccino vs …" pages (see the competitive rule above).
