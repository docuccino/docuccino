<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * The three answers a contract can give to "what holds this parameter's values to account", of which
 * only one is something a validator can be handed. {@see ParameterSchema} reads them off a parameter.
 *
 * The other two are BOTH "nothing was checked" and they are different facts, so they are different
 * cases: one is a declaration written in a grammar this check does not decode, the other is a document
 * that says nothing at all. A caller matching on this is told which, and PHP tells it off for handling
 * only some of them.
 */
enum ParameterSchemaKind
{
    /** `schema` holds a schema — an object, or the boolean `true`/`false`, which are schemas too. */
    case Checkable;

    /** Documented with `content` instead: a real declaration, in a grammar this check does not decode. */
    case Content;

    /** No `schema`, and no `content` either — or a `schema` that is a string, a number or a null. */
    case Absent;
}
