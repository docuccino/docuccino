<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Depot;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Metronome;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Milestone;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Placard;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Sandglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Signpost;
use RuntimeException;

/**
 * One action per model whose eloquent notices are decided by what its response publishes: the model no
 * source yields a column for, beside the two whose only keys arrive from an append and an eager load;
 * and the model that really lost its date format, beside the four an override never reaches. Documented,
 * never dispatched.
 */
final class ModelNoticesController
{
    public function showBlank(): Blank
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showPlacard(): Placard
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showDepot(): Depot
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showChronicle(): Chronicle
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showMilestone(): Milestone
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showSandglass(): Sandglass
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showSignpost(): Signpost
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function showMetronome(): Metronome
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
