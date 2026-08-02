# docuccino/laravel

The Laravel adapter for [Docuccino](https://docuccino.app) — a UIR-based API
documentation generator. It discovers your routes, runs the extension pipeline,
and exports OpenAPI / UIR documents from the real shape of your code.

## Install

```bash
composer require docuccino/laravel
```

Publish the config:

```bash
php artisan vendor:publish --tag=docuccino-config
```

## Usage

Generate and export the default document:

```bash
php artisan docuccino:export
```

Other commands: `docuccino:diff`, `docuccino:validate`, `docuccino:cache`,
`docuccino:clear`. Register your own extensions from any service provider:

```php
use Docuccino\Laravel\Facades\Docuccino;

Docuccino::extend(MyOperationExtension::class);
```

## Documentation

Full docs and the UIR specification live in the main repository:
<https://github.com/docuccino/docuccino>.

## License

MIT. See [LICENSE](LICENSE).
