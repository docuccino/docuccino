# docuccino/attributes

Dependency-free PHP attribute classes for [Docuccino](https://docuccino.app).
These attributes annotate controllers, actions and closure routes so the
Docuccino pipeline can patch or add documentation. The package has no
dependencies beyond PHP itself, so it is safe to require from library code that
wants to expose Docuccino annotations without pulling in the full toolchain.

## Install

```bash
composer require docuccino/attributes
```

## Usage

```php
use Docuccino\Attributes\QueryParameter;

final class FormController
{
    #[QueryParameter(name: 'status', type: 'string', description: 'Filter by status')]
    public function index() { /* … */ }
}
```

## Documentation

Full docs and the UIR specification live in the main repository:
<https://github.com/docuccino/docuccino>.

## License

MIT. See [LICENSE](LICENSE).
