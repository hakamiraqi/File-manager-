<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\Book;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Services\Library\HoldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function __construct(private HoldService $holds) {}

    public function store(Request $request, Book $book): RedirectResponse
    {
        $hold = $this->holds->place(LibraryMember::forUser($request->user()), $book);

        return back()->with('success', $hold->status === Hold::READY
            ? 'تم الحجز. الكتاب جاهز للاستلام من المكتبة.'
            : 'تم الحجز. سنبلغك عند توفر نسخة.');
    }

    public function destroy(Request $request, Hold $hold): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canManageLibrary() || $hold->member?->user_id === $user->id, 403);
        abort_unless(in_array($hold->status, [Hold::WAITING, Hold::READY], true), 422);

        $this->holds->cancel($hold);

        return back()->with('success', 'تم إلغاء الحجز.');
    }
}
