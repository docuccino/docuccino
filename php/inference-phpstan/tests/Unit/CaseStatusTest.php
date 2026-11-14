<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeStatusValue;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use Docuccino\Inference\PhpStan\Throwing\CaseStatus;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Enum\EnumCaseObjectType;
use PHPStan\Type\Type;

/**
 * A `->value` read on an enum case that PHPStan already folded to its constant. Folding over a receiver's
 * cases needs PHPStan's reflection, so it is proven where that runs — the `fixture` group
 * (`CaseStatusResponseTest`, `ThrowStatusTest`).
 *
 * @param  Closure(Node\Expr): Type  $types
 * @return array{array{status: int|null, files: list<string>}, string}
 */
function caseStatusOf(string $code, Closure $types): array
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];
    $read = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\PropertyFetch::class);
    expect($read)->toBeInstanceOf(Node\Expr\PropertyFetch::class);

    $scope = test()->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback($types);

    $adapter = new ScriptedRuntimeAdapter([]);
    $files = new FileAnalyzer($adapter, new FileWalks($adapter));
    $status = new CaseStatus($files, new ProjectFilter(['/'], static fn (string $file): string => $file));

    return [$status->of($read, $scope), (string) (new ReflectionEnum(ProbeStatusValue::class))->getFileName()];
}

it('depends on the enum a backing value PHPStan already folded is decided in', function (): void {
    // The number is the case's backing value: editing `case Gone = 410` changes the answer, so the enum's
    // file has to invalidate it even though the scope handed the constant over without a fold.
    [$folded, $enum] = caseStatusOf('Fault::Gone->value', static fn (Node\Expr $expr): Type => $expr instanceof Node\Expr\PropertyFetch
        ? new ConstantIntegerType(410)
        : new EnumCaseObjectType(ProbeStatusValue::class, 'Gone'));

    expect($folded)->toBe(['status' => 410, 'files' => [$enum]]);
});
