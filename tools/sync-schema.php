<?php

declare(strict_types=1);

/*
 * Copies every published UIR schema from its authoring home in spec/uir/<version>/ to the copy
 * php/core ships, so `Validator` resolves one out of a vendor/ install rather than over the network.
 *
 * Every version rather than the newest: a published `$id` is served forever, and a guard that only
 * ever looked at the latest would go quiet over each version the moment the next one shipped.
 * `SchemaShippingTest` reads the same directory and holds the copies byte-identical.
 */

$root = dirname(__DIR__);
$source = $root.'/spec/uir';

$versions = array_values(array_filter(
    scandir($source) ?: [],
    static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($source.'/'.$entry),
));
sort($versions);

if ($versions === []) {
    fwrite(STDERR, "No UIR schema versions found under spec/uir.\n");

    exit(1);
}

foreach ($versions as $version) {
    $from = $source.'/'.$version.'/schema.json';
    $to = $root.'/php/core/resources/spec/uir/'.$version.'/schema.json';

    if (! is_file($from)) {
        fwrite(STDERR, "Missing {$from}.\n");

        exit(1);
    }

    @mkdir(dirname($to), 0755, true);

    if (! copy($from, $to)) {
        fwrite(STDERR, "Could not write {$to}.\n");

        exit(1);
    }

    echo 'Synced spec/uir/'.$version.'/schema.json -> php/core/resources/spec/uir/'.$version.'/schema.json'.PHP_EOL;
}
