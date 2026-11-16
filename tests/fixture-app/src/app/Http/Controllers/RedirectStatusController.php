<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Redirects sent with a status of their own, through every helper, the redirector, the factory, the
 * `Redirect` facade and `new`; beside one sent with the framework's 302, one through a helper handed the
 * status, and one whose status nothing can read. Only ever analysed, never dispatched.
 */
class RedirectStatusController extends Controller
{
    public function helper(): RedirectResponse
    {
        return redirect('/archive', 301);
    }

    public function to(): RedirectResponse
    {
        return redirect()->to('/archive', 301);
    }

    public function route(): RedirectResponse
    {
        return redirect()->route('archive.index', [], 308);
    }

    public function facade(): RedirectResponse
    {
        return Redirect::to('/archive', 301);
    }

    public function back(): RedirectResponse
    {
        return back(303);
    }

    public function toRoute(): RedirectResponse
    {
        return to_route('archive.index', [], 308);
    }

    public function factory(): RedirectResponse
    {
        return response()->redirectTo('/archive', 307);
    }

    public function constructed(): RedirectResponse
    {
        return new RedirectResponse('/archive', 301);
    }

    public function helperStatus(): RedirectResponse
    {
        return $this->moved(308);
    }

    public function plain(): RedirectResponse
    {
        return redirect('/archive');
    }

    public function unread(Request $request): RedirectResponse
    {
        return redirect()->to('/archive', $request->integer('status'));
    }

    protected function moved(int $status): RedirectResponse
    {
        return redirect()->to('/archive', $status);
    }
}
