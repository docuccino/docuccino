<?php

declare(strict_types=1);

/**
 * The core package is framework-agnostic (design §6): its only runtime dependencies are
 * psr/container, opis/json-schema, symfony/yaml, nikic/php-parser, phpstan/phpdoc-parser and the
 * dependency-free docuccino/attributes (core reads Docuccino attributes off reflected classes/enums —
 * SchemaIdentity, EnumReflection, the attribute-overrides extension — so the tiny, lockstep-versioned
 * attribute package is a runtime dependency, deliberately NOT the framework or the analysis engine).
 * These arch rules freeze that boundary so an accidental `use Illuminate\…` or `use PHPStan\…` in core
 * (which would couple the vocabulary-free core to a host framework or the static-analysis engine)
 * fails the build.
 */
arch('core never depends on the Laravel framework')
    ->expect('Docuccino\Core')
    ->not->toUse('Illuminate');

// `PHPStan\PhpDocParser\` is the standalone phpdoc parsing library — a small, production-fine grammar
// dependency — not phpstan/phpstan the analyser, which stays banned in core.
arch('core never depends on the PHPStan analyser')
    ->expect('Docuccino\Core')
    ->not->toUse('PHPStan')
    ->ignoring('PHPStan\PhpDocParser');

arch('core never depends on the inference engine')
    ->expect('Docuccino\Core')
    ->not->toUse('Docuccino\Inference');

arch('core never depends on the Laravel adapter')
    ->expect('Docuccino\Core')
    ->not->toUse('Docuccino\Laravel');
