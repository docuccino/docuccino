<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The HTML half of a real Laravel app: an action that renders a Blade template. Only ever analysed,
 * never dispatched — the template it names does not have to exist for the return type to be recovered.
 */
final class DashboardPageController
{
    /** The stock "render a page" signature every Laravel app has. */
    public function index(): View
    {
        return view('dashboard', ['title' => 'Dashboard']);
    }

    /** The same thing with no declared return type, so the type comes from the `view()` helper alone. */
    public function summary()
    {
        return view('dashboard.summary');
    }
}
