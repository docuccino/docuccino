<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;

/**
 * A {@see TraceVisitor} that recovers a `rules()` method's returned rule array from its AST — the
 * FormRequest / laravel-action analogue of {@see InlineRulesVisitor}. Each field's rule value is
 * constant-folded ({@see TypeScope::constantValueOf()}) so `Rule::enum(...)`/`Rule::in(...)` factory
 * descriptors survive (PHPStan would otherwise collapse them to a bare `Enum`/`In` object by the time
 * the return type is an array shape — the reason {@see ShapeToRuleSet} alone silently dropped them,
 * validation §1). Nothing is executed. It never requests descent; the engine already visits every
 * node of the traced method.
 *
 * A field that IS present in the array but whose value folds to no rules (a closure, a `new`
 * rule-object, `Rule::when(...)`, an unresolvable expression) is recorded as unrecoverable so the
 * caller can emit a diagnostic rather than let the field vanish silently.
 */
final class RulesMethodVisitor implements TraceVisitor
{
    /**
     * @var array<string, list<ValidationRule>>
     */
    private array $fields = [];

    /**
     * @var list<string>
     */
    private array $unrecoverable = [];

    public function __construct(
        private readonly ConstValueToRules $folder = new ConstValueToRules,
    ) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Return_ && $node->expr instanceof Array_) {
            $this->harvest($node->expr, $scope);
        }

        return false;
    }

    public function ruleSet(): RuleSet
    {
        return new RuleSet($this->fields);
    }

    /**
     * Field names present in the rules array whose value folded to no rules (never recovered).
     *
     * @return list<string>
     */
    public function unrecoverableFields(): array
    {
        return $this->unrecoverable;
    }

    private function harvest(Array_ $array, TypeScope $scope): void
    {
        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $field = $item->key->value;
            $value = $scope->constantValueOf($item->value);
            $rules = $value === null ? [] : $this->folder->fold($value);

            if ($rules !== []) {
                $this->fields[$field] = $rules;

                continue;
            }

            if (! in_array($field, $this->unrecoverable, true)) {
                $this->unrecoverable[] = $field;
            }
        }
    }
}
