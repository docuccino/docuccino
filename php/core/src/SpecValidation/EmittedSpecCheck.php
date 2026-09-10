<?php

declare(strict_types=1);

namespace Docuccino\Core\SpecValidation;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use JsonException;

/**
 * The OpenAPI emitters holding their own output to the published schema for the version they claim to
 * write, on every build.
 *
 * The UIR document has been validated against its schema on every build since the pipeline existed; the
 * artifact people actually consume was validated against nothing. That gap is not a lint — a dangling
 * `$ref`, a schema shape no version accepts or an `operationId` two operations share is a defect in what
 * WE wrote, so the reader it addresses cannot act on it the way a lint's reader can, and no configuration
 * decides whether it is checked. It runs unconditionally, like {@see Validator}, for the same reason.
 *
 * What it costs is why that is affordable: a 46 KB document validates in ~10-45 ms the first time a
 * format is asked for and ~6-12 ms after, against a build measured in seconds. A knob here would only
 * ever let an application switch off the one thing standing between our defect and their generated
 * client.
 *
 * @internal
 */
final class EmittedSpecCheck
{
    /**
     * Every way the emitted artifact fails its own specification, one Error each.
     *
     * $json is the canonical JSON serialisation of what is being emitted — the same bytes for a JSON
     * target, and the same document for a YAML one. Reading the serialisation rather than the array is
     * what makes an empty map show up as `{}` instead of `[]`, which is exactly the class of defect this
     * exists to catch.
     *
     * Undecodable JSON is not reported. The serialiser answers for its own output and cannot produce
     * any, so a failure here would be a diagnostic nobody can act on standing in for an exception
     * somebody can.
     *
     * @return list<Diagnostic>
     */
    public static function diagnostics(string $format, string $json): array
    {
        try {
            $instance = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $diagnostics = [];

        foreach (OpenApiMetaSchema::findings($format, $instance) as $finding) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Error,
                code: 'document.openapi-invalid',
                message: sprintf(
                    'The %s artifact this build emitted does not answer to the published OpenAPI schema for that version: %s.',
                    $format,
                    $finding,
                ),
                help: 'Docuccino writes this file, so an invalid one is a defect in Docuccino rather than '
                    ."in your application, and the artifact was still written.\n"
                    .'Check the one cause you own first: an overlay, or a config value, that writes the '
                    ."position named above.\n"
                    .'Otherwise please report it at https://github.com/docuccino/docuccino/issues with the '
                    .'format, this message, and the smallest route or attribute that reproduces it.',
            );
        }

        return $diagnostics;
    }
}
