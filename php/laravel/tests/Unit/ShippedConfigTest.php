<?php

declare(strict_types=1);

use Illuminate\Foundation\Console\ConfigCacheCommand;

/**
 * The published config file has to be pure data. Laravel loads every file in `config/` at boot, so
 * one class reference here fatals an app that installed Docuccino as a dev dependency and then boots
 * production with `--no-dev` — the packages are pruned, the class is gone.
 */
it('references no class and calls nothing but env', function (): void {
    // Token-scanning rather than a runtime include: the tokenizer keeps comments and strings in their
    // own token kinds, so the commented-out `App\Docs\…::class` examples are ignored while an unused
    // import — which a plain `require` would never even resolve — is still caught.
    $tokens = PhpToken::tokenize((string) file_get_contents(dirname(__DIR__, 2).'/config/docuccino.php'));

    $classReferences = [];
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token->is([T_USE, T_NEW, T_DOUBLE_COLON, T_ATTRIBUTE, T_INSTANCEOF, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            $classReferences[] = $token->text;

            continue;
        }

        // A bare T_STRING is `true`/`false`/`null` or an array key; one followed by `(` is a call.
        if (! $token->is(T_STRING)) {
            continue;
        }

        $next = $index + 1;
        while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
            $next++;
        }

        if (isset($tokens[$next]) && $tokens[$next]->text === '(') {
            $calls[] = $token->text;
        }
    }

    expect($classReferences)->toBe([])
        ->and(array_values(array_unique($calls)))->toBe(['env']);
});

it('defaults the engine mode to the in-process literal', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/docuccino.php';

    expect(data_get($config, 'engine.mode'))->toBe('in-process');
});

/*
 * And it has to survive `config:cache`, which the production guide now states as a property of the
 * file. The command serializes the WHOLE config array with `var_export()` and requires it back
 * ({@see ConfigCacheCommand::handle}), so one closure anywhere under `docuccino` fails the command for
 * the entire application and not just for this package — which is why route filtering and tag mapping
 * name a class rather than taking a predicate. Round-tripping the real file is total where a token scan
 * for `fn`/`function` would only be a guess at the shapes.
 */
it('round-trips through the serialization config:cache uses', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/docuccino.php';

    /** @var array<string, mixed> $cached */
    $cached = eval('return '.var_export($config, true).';');

    expect($cached)->toBe($config)
        // And what a closure under `docuccino` would do to the command, which is the reason the file
        // holds none: `var_export` writes one as `\Closure::__set_state(...)`, and requiring that back
        // fatals. The round-trip above is the assertion; this is what it is guarding against.
        ->and(static fn (): mixed => eval('return '.var_export(['gate' => static fn (): bool => true], true).';'))
        ->toThrow(Error::class, 'Call to undefined method Closure::__set_state()');
});

it('reads config:cache as still serializing the config with var_export', function (): void {
    // The premise of the test above. The command itself cannot run in this harness — it re-bootstraps
    // a fresh application from the testbench skeleton, which never registers this package, so the
    // config under test is not in the array it caches — so the step it fails at is exercised directly
    // and the step is pinned here. If the framework ever serializes config some other way, this fails
    // and the claim gets re-checked rather than repeated.
    $file = (new ReflectionClass(ConfigCacheCommand::class))->getFileName();

    expect((string) file_get_contents((string) $file))->toContain('var_export($config, true)');
});
