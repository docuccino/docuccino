# docuccino/attributes

Dependency-free PHP attribute classes for [Docuccino](https://docuccino.app).
These attributes annotate controllers, actions and closure routes so the
Docuccino pipeline can patch or add documentation. The package has no
dependencies beyond PHP itself, so it is safe to require from library code that
wants to expose Docuccino annotations without pulling in the full toolchain.
`docuccino/core` requires it at runtime (core reflects these attributes off your
classes and enums), so it is versioned in lockstep with the rest of Docuccino.

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

Every attribute — with signatures and examples — is documented in the
[attributes reference](https://docs.docuccino.app/reference/attributes/) at
<https://docs.docuccino.app>.

## License

MIT. See [LICENSE](LICENSE).
