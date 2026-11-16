<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Contributes the `spatie/laravel-data` global config to the environment digest (design §10, A4): the
 * response wrap key (`data.wrap`), the name-mapping strategy (`data.name_mapping_strategy.input`/
 * `.output` — a whole-class default rename), and what a date is read and written with (`data.date_format`,
 * a format or a list of them, `data.casts` and `data.transformers`). Each reshapes every documented Data
 * class but reflects no route file, so it must key the warm-fragment cache. Gated with the spatie-data
 * integration.
 */
final class SpatieDataDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function digest(): string
    {
        return implode("\0", [
            'wrap',
            $this->string('data.wrap'),
            'input-mapper',
            $this->string('data.name_mapping_strategy.input'),
            'output-mapper',
            $this->string('data.name_mapping_strategy.output'),
            'date-format',
            $this->json('data.date_format'),
            'transformers',
            $this->json('data.transformers'),
            'casts',
            $this->json('data.casts'),
        ]);
    }

    /** Any value, lists and maps included, in one spelling per value. */
    private function json(string $key): string
    {
        return json_encode($this->config->get($key), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : '';
    }
}
