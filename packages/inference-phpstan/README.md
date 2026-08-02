# docuccino/inference-phpstan

The PHPStan / Larastan type-inference engine for
[Docuccino](https://docuccino.app). It implements the core `TypeEngine`
contract, analysing your controllers and DTOs to infer response and exception
shapes from the real types in your code. You normally do not use this package
directly — `docuccino/laravel` wires it in behind the `TypeEngine` boundary —
but it can be consumed standalone by any adapter that depends on
`docuccino/core`.

## Install

```bash
composer require docuccino/inference-phpstan
```

## Usage

The engine is constructed behind the core `TypeEngine` interface and queried by
the pipeline:

```php
$analysis = $typeEngine->analyzeAction($actionRef); // ReturnSite[], ThrownException[], …
```

See `docuccino/laravel` for the wired-up integration.

## Documentation

Full docs and the UIR specification live in the main repository:
<https://github.com/docuccino/docuccino>.

## License

MIT. See [LICENSE](LICENSE).
