<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * Website pages the apps open as they are (2026-11-10): the library map editor, its sticker
 * sheet, the RFID station and the printable book labels and reader cards. The API hands
 * the app a short-lived signed link; opening it signs that browser in as the same user and
 * goes to the page. Each page keeps its own permission checks.
 */
class AppPageController extends Controller
{
    /**
     * page => [route, ability, inside the app's web view?]. Printing pages open in the
     * phone's browser (a web view cannot print), so they keep the site's normal look there.
     */
    public const PAGES = [
        'map-editor' => ['library.manage.map', 'manage-library', true],
        'map-labels' => ['library.manage.map.labels', 'manage-library', false],
        'rfid' => ['library.manage.rfid', 'manage-library', true],
        'labels' => ['library.manage.labels', 'manage-library', false],
        'cards' => ['library.manage.members.cards', 'manage-library', false],
        // The office editor for one file (ids[0]); the file's own permission is checked there.
        'office' => ['office.edit', null, true],
    ];

    /** API: the signed link for one page. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'in:'.implode(',', array_keys(self::PAGES))],
            'books' => ['array', 'max:500'], 'books.*' => ['integer'],
            'copies' => ['array', 'max:500'], 'copies.*' => ['integer'],
            'ids' => ['array', 'max:500'], 'ids.*' => ['integer'],
        ]);
        [, $ability] = self::PAGES[$data['page']];
        abort_unless($ability === null || $request->user()->can($ability), 403, 'لا تملك صلاحية هذه الصفحة.');

        $params = array_filter([
            'user' => $request->user()->id,
            'page' => $data['page'],
            'books' => $data['books'] ?? null,
            'copies' => $data['copies'] ?? null,
            'ids' => $data['ids'] ?? null,
        ]);

        return response()->json([
            'path' => URL::temporarySignedRoute('app.open', now()->addMinutes(5), $params, absolute: false),
            'external' => ! self::PAGES[$data['page']][2],
        ]);
    }

    /** Web: the signed link itself. */
    public function open(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(false), 403, 'انتهت صلاحية الرابط، افتحه من التطبيق مرة أخرى.');
        $page = self::PAGES[(string) $request->query('page')] ?? abort(404);
        [$route, $ability, $inApp] = $page;

        $user = User::find($request->integer('user'));
        abort_unless($user && $user->is_active && ($ability === null || $user->can($ability)), 403);
        if (Auth::id() !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }
        if ($inApp) {
            $request->session()->put('in_app', true);
        }

        $params = array_filter([
            'books' => array_map('intval', (array) $request->query('books', [])) ?: null,
            'copies' => array_map('intval', (array) $request->query('copies', [])) ?: null,
            'ids' => array_map('intval', (array) $request->query('ids', [])) ?: null,
        ]);

        if ($route === 'office.edit') {
            return redirect()->route($route, ['file' => (int) ($params['ids'][0] ?? 0)]);
        }

        return redirect()->route($route, $params);
    }
}
