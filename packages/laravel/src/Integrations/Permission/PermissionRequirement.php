<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Permission;

/**
 * One authorization requirement recovered from a `spatie/laravel-permission` middleware: its `type`
 * (`role` | `permission` | `role_or_permission`), the pipe-separated `values` it demands (any-of),
 * and the optional `guard` a `,guard` suffix names. Feeds both the `x-permissions` extension member
 * and the generated description line.
 */
final readonly class PermissionRequirement
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $type,
        public array $values,
        public ?string $guard = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type, 'values' => $this->values];
        if ($this->guard !== null) {
            $out['guard'] = $this->guard;
        }

        return $out;
    }

    /** The human description line (e.g. "Requires permission: edit articles"). */
    public function describe(): string
    {
        $label = match ($this->type) {
            'role' => 'Requires role',
            'role_or_permission' => 'Requires role or permission',
            default => 'Requires permission',
        };

        return $label.': '.implode(', ', $this->values);
    }
}
