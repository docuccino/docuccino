<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\ChoiceRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\ConfirmedRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\DateFormatRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\ExistsRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\FileRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\PresenceRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\RegexRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\SizeRuleTransformer;
use Docuccino\Core\Extensions\Validation\Transformers\TypeRuleTransformer;

/**
 * The built-in per-rule transformer chain covering the Laravel rule floor. A user prepends their
 * own {@see RuleTransformer} ahead of these to add or override a rule — the same extensibility the
 * type→schema chain offers.
 */
final class BuiltInRuleTransformers
{
    /**
     * @return list<RuleTransformer>
     */
    public static function all(): array
    {
        return [
            new PresenceRuleTransformer,
            new TypeRuleTransformer,
            new DateFormatRuleTransformer,
            new FileRuleTransformer,
            new ChoiceRuleTransformer,
            new ExistsRuleTransformer,
            new RegexRuleTransformer,
            new SizeRuleTransformer,
            new ConfirmedRuleTransformer,
        ];
    }
}
