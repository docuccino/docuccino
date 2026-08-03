# Docuccino Documentation Style Guide (binding — from Tom, 2026-08-03)

**The Laravel documentation is the gold standard.** Take pointers and language from it:
warm, direct, confident prose; short paragraphs; every section earns its place; code
examples that a reader can paste and run.

## Audience & framing

- Written for **new developers adopting Docuccino** — never for ourselves. Zero
  references to Eos, Tribepad, internal project history, phases, review waves, or agents.
- No internal workings (arch rules, test infrastructure, monorepo mechanics) unless the
  detail adds value to the reader. Prefer outcome framing, e.g.:
  > "Every official integration is built with the same tools we expose to you — you have
  > access to everything we used to build Docuccino."
- **Don't dog on Scramble across pages.** Competitive comparison lives in exactly ONE
  well-crafted page — "Docuccino vs Scramble" — which is generous in tone and contains
  the how-to-migrate material. Everywhere else, Docuccino stands on its own merits.

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

## Language details

- "you" for the reader; "Docuccino" (never "we" claiming the reader's context).
- Present tense, active voice. British or American spelling — pick one (American, matching
  Laravel docs) and stay consistent.
- Code examples use realistic domain names (invoices, orders, users) — not foo/bar,
  and not our workbench's internal fixtures.
