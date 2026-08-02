<?php

declare(strict_types=1);

use Docuccino\Laravel\Provenance\LaravelSourcePathResolver;

/**
 * The resolver keeps provenance `source.file` paths portable (design §4): base-path-relative for
 * app files, composer-root-relative for files outside the base path (the workbench, path packages).
 */
it('relativises a file under the base path', function (): void {
    $laravelPackage = dirname(__DIR__, 2); // packages/laravel
    $file = $laravelPackage.'/src/Provenance/LaravelSourcePathResolver.php';

    $resolver = new LaravelSourcePathResolver($laravelPackage);

    expect($resolver->relative($file))->toBe('src/Provenance/LaravelSourcePathResolver.php');
});

it('falls back to the nearest composer.json root for files outside the base path', function (): void {
    $laravelPackage = dirname(__DIR__, 2);
    $file = $laravelPackage.'/src/Provenance/LaravelSourcePathResolver.php';

    // A base path that does not contain the file forces the composer-root walk; packages/laravel
    // carries its own composer.json, so the path stays portable rather than absolute.
    $resolver = new LaravelSourcePathResolver('/definitely/not/the/base/path');

    expect($resolver->relative($file))->toBe('src/Provenance/LaravelSourcePathResolver.php');
});

it('returns the path verbatim when neither the base path nor a composer root applies', function (): void {
    $file = '/'.uniqid('docuccino-no-composer-root-', true).'/nested/File.php';

    $resolver = new LaravelSourcePathResolver('/some/other/base');

    expect($resolver->relative($file))->toBe($file);
});
