<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

/**
 * The entry point for the API Resources integration. The `JsonResource` mapper is always on
 * (illuminate/http ships with every Laravel app); the JSON:API pieces are added only when Laravel's
 * first-party `JsonApiResource` class exists (`class_exists` guard), so older Laravel versions are
 * unaffected.
 */
final class ApiResourcesIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        $extensions = [JsonResourceSchema::class];

        if (class_exists(ResourceReflector::JSON_API_RESOURCE)) {
            $extensions[] = JsonApiResourceSchema::class;
            $extensions[] = JsonApiParametersExtension::class;
        }

        return $extensions;
    }
}
