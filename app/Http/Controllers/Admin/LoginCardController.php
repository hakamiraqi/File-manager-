<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\LoginCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «بطاقات الدخول» in the admin area (2026-11-09, by request, like the clinics system's): choose
 * students (tick them, «تحديد الكل», a group or a whole search), make their cards with new
 * passwords, and print them; the login files stay here to print again, download or delete, and
 * a file from the hosting is uploaded on the offline server to save the same passwords there.
 */
class LoginCardController extends Controller
{
    private const FILTERS = ['q' => ['nullable', 'string', 'max:100'], 'group' => ['nullable', 'integer'], 'who' => ['nullable', 'in:students,staff,all'], 'active' => ['nullable', 'in:0,1']];

    public function index(): Response
    {
        return Inertia::render('Admin/LoginCards', [
            'settings' => LoginCards::settings(),
            'groups' => Group::orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'count' => $g->members()->count()]),
            'files' => LoginCards::files(),
            'offline' => (bool) config('offline.edition'),
        ]);
    }

    /** The list to pick from (a page of it), or with ids=1 every id that matches, for «تحديد كل النتائج». */
    public function users(Request $request): JsonResponse
    {
        $f = $request->validate(self::FILTERS + ['page' => ['nullable', 'integer', 'min:1'], 'ids' => ['nullable', 'boolean']]);
        $q = LoginCards::query($f)->orderBy('name');
        if ($request->boolean('ids')) {
            return response()->json(['ids' => $q->limit(5000)->pluck('id')]);
        }
        $page = $q->paginate(50, ['id', 'name', 'email', 'student_number', 'is_active', 'last_login_at', 'card_code']);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => str_ends_with((string) $u->email, '@students.invalid') ? null : $u->email,
                'number' => $u->student_number, 'active' => (bool) $u->is_active, 'has_card' => filled($u->card_code), 'last_login' => $u->last_login_at?->toIso8601String()]),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last' => $page->lastPage(),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'user_ids.*' => ['integer'],
            'new_passwords' => ['required', 'boolean'],
            'label' => ['nullable', 'string', 'max:60'],
            'login_url' => ['nullable', 'url', 'max:255'],
            'alt_url' => ['nullable', 'url', 'max:255'],
            'wifi' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        Setting::setMany(['cards_login_url' => $data['login_url'] ?? '', 'cards_alt_url' => $data['alt_url'] ?? '', 'cards_wifi' => $data['wifi'] ?? '', 'cards_note' => $data['note'] ?? '']);
        $users = User::whereIn('id', $data['user_ids'])->where('role', '!=', 'admin')->get();
        abort_if($users->isEmpty(), 422, 'لم يُختر أحد.');
        $made = LoginCards::make($users, (bool) $data['new_passwords'], trim((string) ($data['label'] ?? '')));
        ActivityLog::record('admin.login_cards', ($data['new_passwords'] ? 'أعطى كلمات مرور جديدة وصنع بطاقات دخول لـ ' : 'صنع بطاقات دخول (QR) لـ ').$made['count'].' مستخدماً');

        return response()->json($made + ['print' => route('admin.cards.print', $made['file'])]);
    }

    /** The cards of a login file, A4 pages of 10 to cut along the dashed lines. */
    public function print(Request $request, string $name): Response
    {
        $path = LoginCards::path($name) ?? abort(404);

        return Inertia::render('Admin/LoginCardsPrint', [
            'name' => $name,
            'cards' => LoginCards::read($path),
            'settings' => LoginCards::settings(),
        ]);
    }

    public function download(string $name): BinaryFileResponse
    {
        $path = LoginCards::path($name) ?? abort(404);

        return response()->download($path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroy(string $name): RedirectResponse
    {
        $path = LoginCards::path($name) ?? abort(404);
        unlink($path);

        return back()->with('success', 'حُذف ملف البطاقات.');
    }

    /** The offline server: the passwords and QR codes of a login file made on the hosting. */
    public function apply(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $rows = LoginCards::read($request->file('file')->getRealPath());
        abort_if(! $rows || ! array_key_exists('password', $rows[0]) || ! array_key_exists('student_number', $rows[0]), 422, 'هذا ليس ملف بطاقات دخول (يجب أن يحوي الأعمدة student_number و password).');
        $r = LoginCards::apply($rows);
        LoginCards::save($rows, 'من-الاستضافة');
        ActivityLog::record('admin.login_cards', 'حفظ كلمات مرور '.$r['updated'].' مستخدماً من ملف بطاقات');

        return response()->json($r);
    }
}
