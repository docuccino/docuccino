<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use Docuccino\Inference\PhpStan\Support\PlainContent;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Peels the fluent tail off a response expression — `->setStatusCode(202)`, `->header('X-Total', $n)`,
 * `->setData($rows)` — so {@see ResponseShapeRefiner} can refine the response where it was BUILT and then
 * apply what the chain restated. Without this a status the application states plainly is lost, or worse
 * inherited from the receiver: `response()->json($body)` types as `JsonResponse<…, 200>` and
 * `setStatusCode()` returns `static`, so the 200 survives a `->setStatusCode(201)` unchallenged.
 *
 * A call whose RECEIVER is a response the refiner reads where it is built — a framework one, a file, stream or
 * redirect among them, or an application's subclass of one ({@see ResponseShapeRefiner::responseRoot()}) — and
 * that the INSTALLED framework declares as handing the same object back is a link; any other call is the
 * PRODUCER the chain was built on, and the refiner takes it from there. Each link is read for the three facts
 * the document carries — the status, the media type and the body — and a link reports only the facts it can
 * touch ({@see LINKS}): a `->cookie()` touches none, a `->setData($rows)` the body alone, and one this cannot
 * read is UNKNOWN in each fact it could have set, never refused. Refusing would leave the receiver's own
 * shape standing where the code plainly replaced it — the `JsonResponse<…, 200>` of `response()->json()`
 * published for a status the endpoint may never send. A link not in {@see LINKS}, a call this cannot name,
 * and one an application's subclass overrides may have touched any of the three, so all three are unknown and
 * the response keeps only its class.
 *
 * The status is read as the refiner reads every other one, so `$ok ? 200 : 503` is both codes and
 * `Reason::Expired->status()` the one its case maps to; a body is read as the response's constructor reads
 * the same argument.
 *
 * @phpstan-type PeeledChain array{receiver: Node\Expr, status: LiteralT|UnionT|null, statusUnknown: bool, contentType: string|null, contentTypeUnknown: bool, body: DType|null, bodyUnknown: bool}
 * @phpstan-type ChainLink array{status: LiteralT|UnionT|null, statusUnknown: bool, contentType: string|null, contentTypeUnknown: bool, body: DType|null, bodyUnknown: bool}
 *
 * @internal
 */
final class FluentResponseChain
{
    /**
     * Every link read by name, and what it touches. `withStatus()` is absent: it is PSR-7's setter, which no
     * response class read here declares.
     */
    private const LINKS = [
        'setStatusCode' => self::STATUS,
        'header' => self::HEADER,
        'withHeaders' => self::HEADERS,
        'setContent' => self::CONTENT,
        'setData' => self::DATA,
        'setEncodingOptions' => self::ENCODING,
        'setCallback' => self::CALLBACK,
        'withCallback' => self::CALLBACK,
        'cookie' => self::COOKIE,
        'withCookie' => self::COOKIE,
        'withoutCookie' => self::COOKIE,
    ];

    /** Sets the status. */
    private const STATUS = 'status';

    /** Sets one header by name and value: the media type, or nothing documented. */
    private const HEADER = 'header';

    /** Sets a map of headers at once. */
    private const HEADERS = 'headers';

    /** Replaces the body with content, as the constructor's first argument would. */
    private const CONTENT = 'content';

    /** Replaces a JSON body with data, as the constructor's first argument would. */
    private const DATA = 'data';

    /** Re-encodes a JSON body, which only some flags change the shape of. */
    private const ENCODING = 'encoding';

    /** Wraps a JSON body in a JavaScript call, labelled as JavaScript, unless the callback is null. */
    private const CALLBACK = 'callback';

    /** Sets a cookie, which is no fact the document carries. */
    private const COOKIE = 'cookie';

    /** The encoding flags that change what a JSON body is: an empty list sent as an object, a numeric string as a number. */
    private const RESHAPING_FLAGS = JSON_FORCE_OBJECT | JSON_NUMERIC_CHECK;

    /**
     * @param  Closure(Node\Expr, Scope): (LiteralT|UnionT|null)  $statusIn  the refiner's status reader
     * @param  Closure(string): ?string  $rootOf  the framework response a class is or derives from, where the refiner reads it ({@see ResponseShapeRefiner::responseRoot()})
     * @param  Closure(Type): ?DType  $payloadIn  the refiner's reader of a JSON body
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly TypeTranslator $translator,
        private readonly Closure $statusIn,
        private readonly Closure $rootOf,
        private readonly Closure $payloadIn,
    ) {}

    /**
     * The receiver the chain was built on plus what its links stated, or null when there is no chain here
     * — either nothing was peeled, or a call on the response hands back something other than it.
     *
     * Merging is outermost-first because the outermost call is the one that ran LAST: `->setStatusCode(201)
     * ->setStatusCode(202)` sends a 202, and a replacing `Content-Type` header works the same way.
     *
     * @return PeeledChain|null
     */
    public function peel(Node\Expr $expr, Scope $scope): ?array
    {
        $status = null;
        $statusUnknown = false;
        $contentType = null;
        $unknown = false;
        $body = null;
        $bodyUnknown = false;
        $current = $expr;
        $peeled = false;

        while ($current instanceof Node\Expr\MethodCall) {
            $link = $this->readLink($current, $scope);
            if ($link === null) {
                break; // a producer, not a mutator — the shape is this expression's
            }
            if ($link === false) {
                return null; // a call on a response that hands back something else: no chain to read
            }

            // Whichever fact a link settles, it settles for the chain: an inner link cannot undo an outer
            // one, and neither can it undo an outer one this could not read.
            if ($status === null && ! $statusUnknown) {
                $status = $link['status'];
                $statusUnknown = $link['statusUnknown'];
            }
            if ($contentType === null && ! $unknown) {
                $contentType = $link['contentType'];
                $unknown = $link['contentTypeUnknown'];
            }
            if ($body === null && ! $bodyUnknown) {
                $body = $link['body'];
                $bodyUnknown = $link['bodyUnknown'];
            }
            $current = $current->var;
            $peeled = true;
        }

        return $peeled
            ? ['receiver' => $current, 'status' => $status, 'statusUnknown' => $statusUnknown, 'contentType' => $contentType, 'contentTypeUnknown' => $unknown, 'body' => $body, 'bodyUnknown' => $bodyUnknown]
            : null;
    }

    /**
     * One link of a chain, in three answers: `null` = the call is not a mutation of a response at all (its
     * receiver is something else), so it is the base; `false` = it does not hand the response back, so the
     * chain is off; an array = what this link stated, each fact it cannot touch left as it was.
     *
     * @return ChainLink|false|null
     */
    private function readLink(Node\Expr\MethodCall $call, Scope $scope): array|false|null
    {
        $receiver = $this->translator->translate($scope->getType($call->var));
        $root = $receiver instanceof ClassT ? ($this->rootOf)($receiver->fqcn) : null;
        if (! $receiver instanceof ClassT || $root === null) {
            return null;
        }

        if (! $call->name instanceof Node\Identifier) {
            return self::unread(); // `$response->{$method}()` — a mutation this cannot even name
        }

        $name = $call->name->toString();
        $declared = $this->declared($receiver->fqcn, $name);
        if ($declared === null || $call->isFirstClassCallable()) {
            return false;
        }

        // An application's own override may do anything, whatever the framework's method of the name does.
        $kind = $declared['own'] ? null : (self::LINKS[$name] ?? null);
        $args = ArgumentSlots::of($call->getArgs(), $declared['parameters']);

        return match ($kind) {
            self::STATUS => $this->readStatus($args, $scope),
            self::HEADER => $this->readHeader($args, $scope),
            self::HEADERS => $this->readHeaders($args, $scope),
            self::CONTENT => $this->readContent($args, $root, $scope),
            self::DATA => $this->readData($args, $scope),
            self::ENCODING => self::readEncoding($args, $scope),
            self::CALLBACK => self::readCallback($args, $scope),
            self::COOKIE => self::untouched(),
            default => self::unread(),
        };
    }

    /**
     * A method as the INSTALLED framework declares it — its parameter names, and whether the application
     * wrote it — or null when what it declares is not a link at all: no such method, or one that does not
     * hand back the object it was called on (`static`, or a `@return $this` — which is how the framework's
     * own trait spells the header setters). A vendor package's fluent grammar is a function of the version
     * the app resolved, so this reads that version's own reflection rather than the one it was written
     * against; a release that moved either fact degrades here instead of publishing this major's dialect.
     *
     * @return array{parameters: list<string>, own: bool}|null
     */
    private function declared(string $fqcn, string $method): ?array
    {
        if (! $this->reflectionProvider->hasClass($fqcn)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($fqcn);
        if (! $class->hasNativeMethod($method)) {
            return null;
        }

        $reflection = $class->getNativeMethod($method);
        $variant = $reflection->getVariants()[0];
        if (! $variant->getReturnType() instanceof StaticType) {
            return null;
        }

        $names = [];
        foreach ($variant->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }
        $declaring = $reflection->getDeclaringClass()->getName();
        $root = ($this->rootOf)($declaring);

        return ['parameters' => $names, 'own' => $root !== null && $root !== $declaring];
    }

    /**
     * `setStatusCode(202)`, `setStatusCode($ok ? 200 : 503)` as both codes, or a read on an enum case as the
     * code its case maps to. A status this build cannot fold is still a status the code states, so it is
     * reported unknown: passing the receiver's through would publish one the endpoint may never send.
     *
     * @return ChainLink
     */
    private function readStatus(ArgumentSlots $args, Scope $scope): array
    {
        $code = $args->at(0);
        $status = $code === null ? null : ($this->statusIn)($code, $scope);

        return ['statusUnknown' => $status === null, 'status' => $status] + self::untouched();
    }

    /**
     * `header('Content-Type', 'application/problem+json')` states the media type; `header('X-Total', $n)`
     * states nothing this documents. Anything in between — a name that will not fold, a value that will
     * not, a `$replace` that is not provably true (appending a second `Content-Type` is not the same claim
     * as setting one) — leaves the media type unknown, which is the whole of what a header can affect.
     *
     * @return ChainLink
     */
    private function readHeader(ArgumentSlots $args, Scope $scope): array
    {
        $name = $args->at(0);
        $folded = $name === null ? null : ScalarFold::of($scope->getType($name));
        if ($folded === null || ! is_string($folded[0])) {
            return self::unknownMediaType();
        }
        if (! ContentTypeHeader::names($folded[0])) {
            return self::untouched();
        }

        $replace = $args->at(2);
        $value = $args->at(1);
        if (($replace !== null && ! $scope->getType($replace)->isTrue()->yes()) || $value === null) {
            return self::unknownMediaType();
        }

        $media = ScalarFold::of($scope->getType($value));

        return $media !== null && is_string($media[0])
            ? ['contentType' => $media[0]] + self::untouched()
            : self::unknownMediaType();
    }

    /**
     * `withHeaders(['Content-Type' => '…'])`. Only a written array can be read: a map assembled elsewhere
     * may carry the media type without saying so here, and a link that might have set one is not a link
     * that provably set none.
     *
     * @return ChainLink
     */
    private function readHeaders(ArgumentSlots $args, Scope $scope): array
    {
        $headers = $args->at(0);
        if (! $headers instanceof Node\Expr\Array_) {
            return self::unknownMediaType();
        }

        return ['contentType' => ContentTypeHeader::inArray($headers, $scope)] + self::untouched();
    }

    /**
     * `setContent($content)`, read as the response's constructor reads its content ({@see PlainContent}): text,
     * nothing, a rendered view, or — for Laravel's plain response, which morphs it — JSON, relabelled with the
     * default media type. A JSON response sends content as it is, which is no JSON body this can name.
     *
     * @return ChainLink
     */
    private function readContent(ArgumentSlots $args, string $root, Scope $scope): array
    {
        $content = $args->at(0);
        $type = $content === null ? null : $scope->getType($content);
        $morphs = $root === ResponseShapeRefiner::PLAIN_RESPONSE;
        $read = $type === null || in_array($root, ResponseShapeRefiner::JSON_FQCNS, true) ? PlainContent::UNREAD : PlainContent::of($type, $morphs);
        $body = match ($read) {
            PlainContent::EMPTY => new VoidT,
            PlainContent::RENDERED => new ClassT(ResponseShapeRefiner::VIEW),
            PlainContent::TEXT, PlainContent::JSON => $type === null ? null : ($read === PlainContent::TEXT ? PlainContent::text($type) : ($this->payloadIn)($type)),
            default => null,
        };

        // Content that is JSON is relabelled as JSON, and content that may be has an unknown label.
        $relabelled = $read === PlainContent::JSON || ($read === PlainContent::UNREAD && $morphs);

        return ['body' => $body, 'bodyUnknown' => $body === null, 'contentTypeUnknown' => $relabelled] + self::untouched();
    }

    /**
     * `setData($rows)`: the body is the data now, read as the constructor reads it.
     *
     * @return ChainLink
     */
    private function readData(ArgumentSlots $args, Scope $scope): array
    {
        $data = $args->at(0);
        $body = $data === null ? null : ($this->payloadIn)($scope->getType($data));

        return ['body' => $body, 'bodyUnknown' => $body === null] + self::untouched();
    }

    /**
     * `setEncodingOptions($flags)` re-encodes the same data: a body still sent as it was, unless the flags may
     * reshape it ({@see RESHAPING_FLAGS}).
     *
     * @return ChainLink
     */
    private static function readEncoding(ArgumentSlots $args, Scope $scope): array
    {
        $options = $args->at(0);
        $flags = $options === null ? null : ScalarFold::ints($scope->getType($options));
        $kept = $flags !== null && array_filter($flags, static fn (int $each): bool => ($each & self::RESHAPING_FLAGS) !== 0) === [];

        return ['bodyUnknown' => ! $kept] + self::untouched();
    }

    /**
     * `setCallback($name)`: a JSONP body labelled as JavaScript, unless the name is provably null, which
     * changes nothing.
     *
     * @return ChainLink
     */
    private static function readCallback(ArgumentSlots $args, Scope $scope): array
    {
        $callback = $args->at(0);
        if ($args->knows(0) && ($callback === null || $scope->getType($callback)->isNull()->yes())) {
            return self::untouched();
        }

        return ['bodyUnknown' => true, 'contentTypeUnknown' => true] + self::untouched();
    }

    /**
     * A header link that may or may not have set the media type. Neither publishing the receiver's nor
     * inventing one is honest, so the chain reports it unknown and the response falls back to the default.
     *
     * @return ChainLink
     */
    private static function unknownMediaType(): array
    {
        return ['contentTypeUnknown' => true] + self::untouched();
    }

    /**
     * A link that touched no fact the document carries.
     *
     * @return ChainLink
     */
    private static function untouched(): array
    {
        return ['status' => null, 'statusUnknown' => false, 'contentType' => null, 'contentTypeUnknown' => false, 'body' => null, 'bodyUnknown' => false];
    }

    /**
     * A link that may have touched every fact: what the response was built with no longer stands, and only
     * its class does.
     *
     * @return ChainLink
     */
    private static function unread(): array
    {
        return ['status' => null, 'statusUnknown' => true, 'contentType' => null, 'contentTypeUnknown' => true, 'body' => null, 'bodyUnknown' => true];
    }
}
