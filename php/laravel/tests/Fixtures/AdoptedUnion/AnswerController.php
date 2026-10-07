<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Laravel\Tests\Fixtures\TaggedUnion\Attachment;

/** Takes an answer with no declaration, with the sealed union it is built into declared on the action or on the request, and with a union tagged by other values declared; and sends the last one back. */
final class AnswerController
{
    public function store(AnswerRequest $request): void {}

    #[BodyParameter(name: 'answer', type: Answer::class.'|null')]
    #[BodyParameter(name: 'note', type: 'string', description: 'Shown beside the answer.')]
    public function storeDeclared(AnswerRequest $request): void {}

    public function storeTyped(DeclaredAnswerRequest $request): void {}

    #[BodyParameter(name: 'answer', type: Attachment::class.'|null')]
    public function storeMismatched(AnswerRequest $request): void {}

    public function last(): LastAnswer
    {
        return new LastAnswer(new CountAnswer(3));
    }
}
