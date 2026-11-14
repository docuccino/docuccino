<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Illuminate\Validation\ValidationRuleParser;

/*
 * A rule token's parameters are what the server validates with, so they are read as Laravel's own parser reads
 * them: a CSV row, which keeps an empty entry and lets quotes enclose a comma, except a regex, which is one
 * parameter whatever it holds. Laravel's parser is the reference each row is checked against.
 */
it('reads a token\'s parameters as Laravel parses them', function (string $token, array $parameters): void {
    [, $laravel] = ValidationRuleParser::parse($token);

    expect(RuleParsing::token($token)->parameters)->toBe($parameters)
        ->and($laravel)->toBe($parameters);
})->with([
    'no parameters' => ['required', []],
    'plain values' => ['in:a,b', ['a', 'b']],
    'two bounds' => ['between:1,10', ['1', '10']],
    'an empty last entry' => ['in:1,', ['1', '']],
    'an empty first entry' => ['in:,a', ['', 'a']],
    'quoted values' => ['in:"1",""', ['1', '']],
    'a quoted comma' => ['in:"x,y",z', ['x,y', 'z']],
    'a doubled quote' => ['in:"a""b",c', ['a"b', 'c']],
    'a space inside a value' => ['date_format:Y-m-d H:i', ['Y-m-d H:i']],
    'a regex holding commas and quotes' => ['regex:/^"[a-z]+", "[a-z]{1,3}"$/', ['/^"[a-z]+", "[a-z]{1,3}"$/']],
    'a negated regex holding a comma' => ['not_regex:/a,,b/', ['/a,,b/']],
]);
