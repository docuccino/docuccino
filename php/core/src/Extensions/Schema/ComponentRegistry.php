<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Json;

/**
 * Accumulates the reusable schema/response components hoisted during a build: structurally-equal
 * registrations of ONE identity dedupe and a genuine name collision gets a deterministic suffix. The `schemaId` hint
 * (an FQCN) is remembered per component so the assembler can pin its diff identity via
 * {@see IdentityGenerator::namedSchemaId()}.
 *
 * The name a schema registers under is a PROVISIONAL slot: it goes to whoever asked first, which is
 * route order. What each registration ASKED to be called is kept beside it, because that — with the
 * identity — is what {@see schemaRenames()} derives the published name from. See {@see ComponentNames}
 * for why the slot cannot be the answer.
 *
 * @phpstan-import-type Claim from ComponentNames
 *
 * @phpstan-type Snapshot array{schemas: array<string, array<string, mixed>>, schemaIds: array<string, string>, schemaBases: array<string, string>, reservedIds: array<string, string>, responses: array<string, array<string, mixed>>, securitySchemes: array<string, array<string, mixed>>, diagnostics: list<Diagnostic>}
 */
final class ComponentRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $schemas = [];

    /**
     * @var array<string, string>
     */
    private array $schemaIds = [];

    /**
     * The name each registration asked to be called, before any collision suffix: final name → base.
     * A warm cache hit re-registers off this rather than off the name it was cached under, so a
     * suffix a since-deleted route once pushed it onto cannot outlive that route.
     *
     * @var array<string, string>
     */
    private array $schemaBases = [];

    /**
     * Names reserved for a schema identity before its body exists, so a self-reference found
     * mid-expansion resolves to the same (possibly suffixed) name.
     *
     * @var array<string, string> final name → schemaId
     */
    private array $reservedIds = [];

    /**
     * Reusable response components for `components.responses` — e.g. the shared `Problem*` responses
     * a Problem Details preset references from many operations.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $responses = [];

    /**
     * Security schemes contributed by integrations, e.g. Sanctum `bearer` or Passport `oauth2` when
     * the package is installed and config set no scheme. The assembler merges these under the config
     * schemes, so explicit config wins.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $securitySchemes = [];

    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    /**
     * Register a named schema and return the `{"$ref": …}` array pointing at its final name.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function reference(string $name, array $schema, ?string $schemaId = null): array
    {
        return ['$ref' => '#/components/schemas/'.$this->registerSchema($name, $schema, $schemaId)];
    }

    /**
     * Register a named schema, returning the final component name (suffixed on genuine collision).
     * A structurally-identical re-registration of the same identity is deduped; see {@see mergesInto()}
     * for why two identities are never merged, however alike their bytes.
     *
     * @param  array<string, mixed>  $schema
     */
    public function registerSchema(string $name, array $schema, ?string $schemaId = null): string
    {
        $base = self::sanitize($name);

        if ($schemaId !== null) {
            // Same identity (same class FQCN) already registered — reuse it, so one class is one
            // component however often it's referenced.
            $existing = array_search($schemaId, $this->schemaIds, true);
            if ($existing !== false) {
                return (string) $existing;
            }

            // Materialise into the name reserved up front for a self-referential class whose
            // cycle-breaking $ref went out during expansion.
            $reserved = array_search($schemaId, $this->reservedIds, true);
            if ($reserved !== false) {
                $reserved = (string) $reserved;
                unset($this->reservedIds[$reserved]);
                $this->schemas[$reserved] = $schema;
                $this->schemaIds[$reserved] = $schemaId;

                return $reserved;
            }
        }

        if (! isset($this->schemas[$base]) && ! isset($this->reservedIds[$base])) {
            $this->store($base, $base, $schema, $schemaId);

            return $base;
        }

        if (isset($this->schemas[$base]) && $this->mergesInto($base, $schema, $schemaId)) {
            return $base;
        }

        $suffixed = $base;
        $n = 1;
        while (
            (isset($this->schemas[$suffixed]) && ! $this->mergesInto($suffixed, $schema, $schemaId))
            || isset($this->reservedIds[$suffixed])
        ) {
            $n++;
            $suffixed = $base.'_'.$n;
        }

        if (! isset($this->schemas[$suffixed])) {
            $this->store($suffixed, $base, $schema, $schemaId);
        }

        return $suffixed;
    }

    /**
     * Whether a registration may collapse into the schema already occupying a slot: same identity, same
     * bytes. Equal bytes alone are not enough — two DISTINCT classes whose shapes happen to coincide get
     * two components, at the cost of one redundant component in the rare case that they do.
     *
     * The alternatives are both worse. Identifying a merged component by its CONTENT would stop the
     * differ tracking a class across a body change, so every edit would read as remove + add; identifying
     * it by the SET of contributors would mutate an existing id whenever another class joined — a
     * distant part of the application renaming one it never touched. Dropping the newcomer's identity
     * (what this used to do) is the same defect a step further on: the surviving component carried
     * whichever id registered first, which is route order.
     *
     * A schema that names no identity has none to lose, so two of those with equal bytes are one claim
     * and still merge — that is the same-class case, which is what dedupe is for.
     *
     * @param  array<string, mixed>  $schema
     */
    private function mergesInto(string $slot, array $schema, ?string $schemaId): bool
    {
        return ($this->schemaIds[$slot] ?? null) === $schemaId
            && self::structurallyEqual($this->schemas[$slot], $schema);
    }

    /**
     * File a schema in the slot it landed on, remembering the name it asked for.
     *
     * @param  array<string, mixed>  $schema
     */
    private function store(string $slot, string $base, array $schema, ?string $schemaId): void
    {
        $this->schemas[$slot] = $schema;
        $this->schemaBases[$slot] = $base;
        if ($schemaId !== null) {
            $this->schemaIds[$slot] = $schemaId;
        }
    }

    /**
     * Replace a registered schema's body, but only where the name still holds the identity the caller
     * expects. The pipeline uses it to re-file a warm cache hit's bodies once it knows which of the
     * names it recorded moved; nothing else should need it.
     *
     * @param  array<string, mixed>  $schema
     */
    public function replaceSchema(string $name, array $schema, ?string $schemaId): void
    {
        if (isset($this->schemas[$name]) && ($this->schemaIds[$name] ?? null) === $schemaId) {
            $this->schemas[$name] = $schema;
        }
    }

    /**
     * Reserve the final component name for a schema identity before its body is built, so a
     * self-reference found mid-expansion can `$ref` the exact name — collision suffix included —
     * that the schema will materialise under. The registry is the sole owner of component naming:
     * reserving one identity twice gives the same name, and a reservation occupies the namespace so
     * a different identity gets suffixed past it.
     */
    public function reserveSchemaName(string $name, string $schemaId): string
    {
        // Already materialised or reserved under this identity — reuse that name.
        $existing = array_search($schemaId, $this->schemaIds, true);
        if ($existing !== false) {
            return (string) $existing;
        }
        $reserved = array_search($schemaId, $this->reservedIds, true);
        if ($reserved !== false) {
            return (string) $reserved;
        }

        $name = self::sanitize($name);
        $final = $name;
        $n = 1;
        while (isset($this->schemas[$final]) || isset($this->reservedIds[$final])) {
            $n++;
            $final = $name.'_'.$n;
        }

        $this->reservedIds[$final] = $schemaId;
        $this->schemaBases[$final] = $name;

        return $final;
    }

    /**
     * The name each schema is published under, where that differs from the provisional slot
     * registration handed it: slot → published name. Derived from what the claims say about
     * themselves, so it survives a build whose routes were discovered in another order — and, being
     * computed from the finished registry, it is the same on a warm cache hit, where nothing
     * re-registers.
     *
     * @return array<string, string>
     */
    public function schemaRenames(): array
    {
        return ComponentNames::resolve($this->schemaClaims());
    }

    /**
     * What every registered schema claims: the name it asked for, the identity behind it, and the
     * bytes it publishes — the last standing in for the identity a schema that names none doesn't have.
     *
     * @return array<string, Claim>
     */
    private function schemaClaims(): array
    {
        $claims = [];
        foreach ($this->schemas as $name => $schema) {
            $claims[(string) $name] = [
                'base' => $this->schemaBases[$name] ?? (string) $name,
                'identity' => $this->schemaIds[$name] ?? null,
                'content' => Json::stable($schema),
            ];
        }

        return $claims;
    }

    /**
     * One diagnostic per name two or more schemas contested, naming every claimant and the name it was
     * published under. A namespace-derived name is only ever the automatic answer, so the warning's job
     * is to offer the better one: `#[SchemaName]`, where the author says what each shape actually is.
     *
     * @return list<Diagnostic>
     */
    public function nameCollisions(): array
    {
        $contests = ComponentNames::contests($this->schemaClaims());
        ksort($contests);

        $out = [];
        foreach ($contests as $contested => $claimants) {
            ksort($claimants);

            $named = [];
            foreach ($claimants as $published => $identity) {
                $named[] = $identity.' as "'.$published.'"';
            }

            $out[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf('Component name "%s" is claimed by distinct schemas; each was published under its own name (%s).', $contested, implode(', ', $named)),
                help: 'Those names are stable but automatic — name the shapes yourself with #[SchemaName] on the source classes.',
            );
        }

        return $out;
    }

    /**
     * Register a reusable response component and return the `{"$ref": …}` array pointing at its
     * final name. Mirrors {@see reference()} for schemas.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function referenceResponse(string $name, array $response): array
    {
        return ['$ref' => '#/components/responses/'.$this->registerResponse($name, $response)];
    }

    /**
     * Register a named response component, returning the final component name (suffixed on genuine
     * collision). A shared response like `ProblemUnauthenticated` dedupes to one hoist however many
     * operations reference it.
     *
     * @param  array<string, mixed>  $response
     */
    public function registerResponse(string $name, array $response): string
    {
        return $this->registerNamed($this->responses, $name, $response, 'responses');
    }

    /**
     * Register a security scheme an integration auto-configured; the returned name is what an
     * operation's `security` requirement references. Dedupes, so a shared `sanctum` hoists once.
     *
     * @param  array<string, mixed>  $definition
     */
    public function registerSecurityScheme(string $name, array $definition): string
    {
        return $this->registerNamed($this->securitySchemes, $name, $definition, 'security schemes');
    }

    /**
     * Hoist a named body into a component bucket, deduping a structurally-equal body and suffixing a
     * genuine collision. Schemas need name reservation for self-reference cycles, so they keep their
     * own variant in {@see registerSchema()}.
     *
     * @param  array<string, array<string, mixed>>  $bucket
     * @param  array<string, mixed>  $body
     */
    private function registerNamed(array &$bucket, string $name, array $body, string $kind): string
    {
        $name = self::sanitize($name);

        if (! isset($bucket[$name])) {
            $bucket[$name] = $body;

            return $name;
        }

        if (self::structurallyEqual($bucket[$name], $body)) {
            return $name;
        }

        $suffixed = $name;
        $n = 1;
        while (isset($bucket[$suffixed]) && ! self::structurallyEqual($bucket[$suffixed], $body)) {
            $n++;
            $suffixed = $name.'_'.$n;
        }

        if (! isset($bucket[$suffixed])) {
            $bucket[$suffixed] = $body;
            $this->diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf('Distinct %s claimed component name "%s"; the later one was hoisted as "%s".', $kind, $name, $suffixed),
                help: 'Disambiguate the source of one of them.',
            );
        }

        return $suffixed;
    }

    /**
     * A restorable snapshot of the whole registry: a route that fails mid-pipeline after registering
     * components rolls back, so it leaves no orphaned components, diagnostics or leaked name
     * reservations behind.
     *
     * @return Snapshot
     */
    public function snapshot(): array
    {
        return [
            'schemas' => $this->schemas,
            'schemaIds' => $this->schemaIds,
            'schemaBases' => $this->schemaBases,
            'reservedIds' => $this->reservedIds,
            'responses' => $this->responses,
            'securitySchemes' => $this->securitySchemes,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * @param  Snapshot  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->schemas = $snapshot['schemas'];
        $this->schemaIds = $snapshot['schemaIds'];
        $this->schemaBases = $snapshot['schemaBases'];
        $this->reservedIds = $snapshot['reservedIds'];
        $this->responses = $snapshot['responses'];
        $this->securitySchemes = $snapshot['securitySchemes'];
        $this->diagnostics = $snapshot['diagnostics'];
    }

    /**
     * Take the diagnostics recorded since a snapshot, removing them from the registry so the assembler
     * cannot report them twice. A route's component diagnostics belong to its fragment: that is what
     * replays them on a warm cache hit, where nothing re-registers and a registration-time report —
     * a name collision above all — would otherwise vanish from a build whose bytes still carry it.
     *
     * @param  Snapshot  $snapshot
     * @return list<Diagnostic>
     */
    public function takeDiagnosticsSince(array $snapshot): array
    {
        $taken = array_slice($this->diagnostics, count($snapshot['diagnostics']));
        $this->diagnostics = $snapshot['diagnostics'];

        return $taken;
    }

    /**
     * Rolls only `components.responses` back to a snapshot's, leaving schemas and diagnostics alone.
     * For an extension that asks a mapper for a shape and then inlines the content itself: the shared
     * response the mapper registered would be an orphan (nothing `$ref`s it, and a warm cache — which
     * re-registers only what an operation references — would never bring it back), while the schemas
     * that inlined content points at must survive.
     *
     * @param  Snapshot  $snapshot
     */
    public function restoreResponses(array $snapshot): void
    {
        $this->responses = $snapshot['responses'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * @return array<string, string>
     */
    public function schemaIds(): array
    {
        return $this->schemaIds;
    }

    /**
     * The name each registered schema asked to be called. A fragment carries these so a warm hit
     * re-registers off the ask rather than off the slot it was cached in.
     *
     * @return array<string, string>
     */
    public function schemaBases(): array
    {
        return $this->schemaBases;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function securitySchemes(): array
    {
        return $this->securitySchemes;
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Record a diagnostic raised while building components, e.g. a validation rule no transformer
     * handled. The assembler folds these into the document's diagnostic channel.
     */
    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    private static function sanitize(string $name): string
    {
        return ComponentNames::sanitize($name);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function structurallyEqual(array $a, array $b): bool
    {
        return Json::stable($a) === Json::stable($b);
    }
}
