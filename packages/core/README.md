# docuccino/core

The framework-agnostic heart of [Docuccino](https://docuccino.app): the UIR
document model, canonicalizer, identity/hashing, JSON-Schema validator and the
OpenAPI / UIR emitters. It has no framework dependency and is consumed by the
framework adapters (e.g. `docuccino/laravel`).

## Install

```bash
composer require docuccino/core
```

## Usage

Emit a canonical OpenAPI 3.2 document from a built UIR document:

```php
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi32Emitter;

$json = (new OpenApi32Emitter())->emit($uirDocument, new EmitOptions());
```

## Documentation

Full docs and the UIR specification live in the main repository:
<https://github.com/docuccino/docuccino>.

## License

MIT. See [LICENSE](LICENSE).
