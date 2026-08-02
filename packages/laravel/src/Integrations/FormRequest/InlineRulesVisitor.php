<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;

/**
 * A {@see TraceVisitor} that recovers an inline `$request->validate([...])` /
 * `Validator::make($data, [...])` rule array from the action body. The rule array is read
 * *statically*: the field keys come straight from the AST and each rule value is constant-folded
 * ({@see TypeScope::constantValueOf()}), so `Rule::enum(...)` descriptors survive — nothing is ever
 * executed. It never requests descent; the engine already visits every node of the action body.
 */
final class InlineRulesVisitor implements TraceVisitor
{
    /**
     * @var array<string, list<ValidationRule>>
     */
    private array $fields = [];

    public function __construct(
        private readonly ConstValueToRules $folder = new ConstValueToRules,
    ) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $rulesArgument = $this->rulesArgument($node);
        if ($rulesArgument instanceof Array_) {
            $this->harvest($rulesArgument, $scope);
        }

        return false;
    }

    public function ruleSet(): RuleSet
    {
        return new RuleSet($this->fields);
    }

    /** The rules-array argument of a `validate()` / `Validator::make()` call, or null. */
    private function rulesArgument(Node $node): ?Node
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'validate') {
            return $node->getArgs()[0]->value ?? null;
        }

        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'make'
            && $this->isValidatorFactory($node)
        ) {
            // Validator::make($data, $rules, ...) — the rules are the second argument.
            return $node->getArgs()[1]->value ?? null;
        }

        return null;
    }

    private function isValidatorFactory(StaticCall $node): bool
    {
        if (! $node->class instanceof Node\Name) {
            return false;
        }

        return $node->class->getLast() === 'Validator';
    }

    private function harvest(Array_ $array, TypeScope $scope): void
    {
        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $value = $scope->constantValueOf($item->value);
            if ($value === null) {
                continue;
            }

            $rules = $this->folder->fold($value);
            if ($rules !== []) {
                $this->fields[$item->key->value] = $rules;
            }
        }
    }
}
