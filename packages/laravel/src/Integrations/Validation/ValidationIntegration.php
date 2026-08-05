<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Laravel\Integrations\Validation\Transformers\ChoiceRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\ConditionalRequiredRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\ConfirmedRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\DateFormatRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\ExistsRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\FileRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\NoOpRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\PresenceRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\RegexRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\SizeRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\TypeRuleTransformer;

/**
 * The always-on Laravel validation integration: it owns the rule vocabulary (the per-rule
 * {@see RuleTransformer} set covering Laravel's DSL floor) and contributes it to the core chain
 * driver as {@see RuleTransformer} extensions — dogfooding the public chain exactly as a user's
 * custom rule would. Its target is `illuminate/validation`, present in every Laravel app, so it is
 * unconditional.
 *
 * The recovery integrations (FormRequest, inline `validate()`, Spatie Data) reuse this one chain:
 * they only produce a {@see RuleSet} and hand it to
 * {@see RouteContext::validation()}.
 */
final class ValidationIntegration
{
    /**
     * The Laravel rule transformer set, in effect order. Registered as {@see RuleTransformer}
     * extension defaults so user transformers can slot ahead of them.
     *
     * @return list<RuleTransformer>
     */
    public static function transformers(): array
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
            new ConditionalRequiredRuleTransformer,
            new NoOpRuleTransformer,
        ];
    }
}
