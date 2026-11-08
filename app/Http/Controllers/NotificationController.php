<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), ['unread', ...UserNotification::CATEGORIES], true) ? $request->query('filter') : 'all';
        $base = UserNotification::where('user_id', $request->user()->id);

        $items = (clone $base)
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when(in_array($filter, UserNotification::CATEGORIES, true), fn ($q) => $q->where('category', $filter))
            ->latest()->latest('id')
            ->paginate(30)->withQueryString()
            ->through(fn (UserNotification $n) => $n->toCard());

        $counts = (clone $base)->selectRaw('category, COUNT(*) as c')->groupBy('category')->pluck('c', 'category');

        return Inertia::render('Notifications/Index', [
            'items' => $items,
            'filter' => $filter,
            'counts' => [
                'all' => (int) $counts->sum(),
                'unread' => (clone $base)->whereNull('read_at')->count(),
                ...collect(UserNotification::CATEGORIES)->mapWithKeys(fn ($c) => [$c => (int) ($counts[$c] ?? 0)])->all(),
            ],
        ]);
    }

    /** The bell's panel: latest notifications and the unread count. */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => UserNotification::unreadCount($user),
            'items' => UserNotification::where('user_id', $user->id)->latest()->latest('id')->limit(8)->get()->map->toCard(),
        ]);
    }

    /** Opening a notification marks it read and goes to what it is about. */
    public function open(Request $request, UserNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->url ? redirect()->to($notification->url) : redirect()->route('notifications.index');
    }

    public function read(Request $request, UserNotification $notification): JsonResponse|RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'تم تحديد كل الإشعارات كمقروءة.');
    }

    public function destroy(Request $request, UserNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->delete();

        return back();
    }
}
