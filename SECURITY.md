# Security Policy

## Supported versions

Security fixes land on the latest minor of the current major. Older majors are not
patched once a new major is released.

| Version | Supported |
| ------- | --------- |
| 1.x     | ✅        |

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it through GitHub's private vulnerability reporting, which keeps the report
confidential until a fix is published:

1. Go to https://github.com/docuccino/docuccino/security/advisories/new
2. Describe the issue, the affected version, and how to reproduce it

Expect an acknowledgement within 5 working days. Once a fix is ready we will
publish it together with a GitHub Security Advisory crediting you, unless you
ask to stay anonymous.

Report vulnerabilities in the packages (`docuccino/core`, `docuccino/attributes`,
`docuccino/inference-phpstan`, `docuccino/laravel`) here too — those repositories
are read-only splits of this monorepo.

## Scope

Docuccino reads your application's source code and emits an OpenAPI document.
Analysis is a build-time job: `docuccino/inference-phpstan` is a dev-only install
and never belongs on a production host. `docuccino/laravel` may be installed in
production, where its only runtime job is serving an already-generated document
through the bundled viewer.

In scope:

- Code execution or file access beyond the analysed project during a documentation run
- Secrets, credentials or other non-public application data leaking into an emitted
  document, a diagnostic, or the fragment cache
- A crafted route, attribute, docblock or overlay that escalates into arbitrary code
  execution
- Non-determinism that lets an emitted document misrepresent the code it came from
- Vulnerabilities in the bundled Scalar viewer assets

Out of scope:

- Findings that require an attacker to already control the source code being
  documented — Docuccino analyses that code by design, and analysis of hostile
  source is not a trust boundary we claim
- Exposing a generated document on a public route: whether documentation is public
  is the host application's decision, configured via `config/docuccino.php`
- Denial of service through deliberately pathological input (very large or deeply
  nested types) in a local development run
