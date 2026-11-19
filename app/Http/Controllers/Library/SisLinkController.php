<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Services\Library\SisLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «ربط نظام الطلبة»: site admins issue or revoke the key SIS uses. */
class SisLinkController extends Controller
{
    public function issue(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return back()->with('success', 'صدر مفتاح ربط نظام الطلبة. انسخه الآن، فلن يظهر مرة أخرى.')->with('sis_key', SisLink::issue());
    }

    public function revoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        SisLink::revoke();

        return back()->with('success', 'أُلغي ربط نظام الطلبة.');
    }
}
