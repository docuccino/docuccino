# docuccino/attributes

[![Latest version](https://img.shields.io/packagist/v/docuccino/attributes?label=packagist)](https://packagist.org/packages/docuccino/attributes)
[![Downloads](https://img.shields.io/packagist/dt/docuccino/attributes)](https://packagist.org/packages/docuccino/attributes)
[![PHP version](https://img.shields.io/packagist/dependency-v/docuccino/attributes/php)](https://packagist.org/packages/docuccino/attributes)
[![CI](https://github.com/docuccino/docuccino/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/docuccino/docuccino/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/docuccino/attributes)](LICENSE)

**Dependency-free PHP attributes for documenting an HTTP API**, used by
[Docuccino](https://docuccino.app).

These attributes annotate controllers, actions, closure routes and the types they use, so the
Docuccino pipeline can patch or add documentation where your code cannot say it on its own — a
description, a query parameter, an example, a security requirement.

The package has **no dependencies beyond PHP itself**, which is the point: a library can expose
Docuccino annotations to its users without pulling in a documentation toolchain. If you are
documenting a Laravel application, you want
**[`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel)**, which pulls this in
for you.

## Install

```bash
composer require docuccino/attributes
```

## Usage

```php
use Docuccino\Attributes\Description;
use Docuccino\Attributes\QueryParameter;

final class InvoiceController
{
    #[QueryParameter(name: 'status', type: 'string', description: 'Filter by status')]
    public function index() { /* … */ }
}

#[Description(text: 'Everything an invoice carries once it has been issued.')]
final class InvoiceData { /* … */ }
```

## Part of Docuccino

| Package | Role |
| --- | --- |
| [`docuccino/laravel`](https://packagist.org/packages/docuccino/laravel) | The Laravel adapter: provider, config, commands, viewer, integrations. **Start here.** |
| [`docuccino/core`](https://packagist.org/packages/docuccino/core) | Framework-agnostic document model, canonicalizer, identities, emitters, diff. |
| [`docuccino/inference-phpstan`](https://packagist.org/packages/docuccino/inference-phpstan) | PHPStan + Larastan type inference. Install as a **dev** dependency. |
| **`docuccino/attributes`** ← you are here | Dependency-free PHP attribute classes. |

## Documentation

Every attribute — with signatures and examples — is documented in the
[attributes reference](https://docs.docuccino.app/laravel/reference/attributes/) at
**[docs.docuccino.app](https://docs.docuccino.app)**.

## Issues and contributing

**This repository is a read-only subtree split** of
[docuccino/docuccino](https://github.com/docuccino/docuccino). Open issues and pull requests on the
monorepo — commits pushed here are overwritten. See
[CONTRIBUTING.md](https://github.com/docuccino/docuccino/blob/main/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
