<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\AuthorData;
use App\Data\AuthorPageData;
use App\Data\PageMetaData;
use Docuccino\Attributes\Response;

/** A page that is its own envelope, returned through its named constructor and declared by class. */
class AuthorPageController extends Controller
{
    public function index(): AuthorPageData
    {
        return AuthorPageData::page([new AuthorData('Ada', 'ada@example.com')], new PageMetaData(1, 1));
    }

    #[Response(status: 200, type: AuthorPageData::class)]
    public function declared(): mixed
    {
        return AuthorPageData::page([new AuthorData('Ada', 'ada@example.com')], new PageMetaData(1, 1));
    }
}
