<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Classes and sections: who is in them, and so who sees an exam given to them.
 * Since 2026-11-09 (by request) a student is in one group only: the students offered for a group
 * are those in no group yet, and adding one who is in another group moves them (when asked to).
 */
class GroupController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $groups = Group::with('owner:id,name')->withCount(['members', 'exams'])->orderBy('stage')->orderBy('name')->get();
        $current = $groups->firstWhere('id', (int) $request->query('group')) ?? $groups->first();

        return Inertia::render('Exams/Manage/Groups', [
            'groups' => $groups->map(fn (Group $g) => [
                'id' => $g->id, 'name' => $g->name, 'stage' => $g->stage, 'department' => $g->department,
                'members' => $g->members_count, 'exams' => $g->exams_count, 'owner' => $g->owner?->name,
                'editable' => $g->isEditableBy($user),
            ])->values(),
            'current' => $current?->id,
            'members' => $current ? $current->members()->orderBy('name')->get(['users.id', 'name', 'email', 'student_number'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => str_ends_with((string) $u->email, '@students.invalid') ? null : $u->email, 'number' => $u->student_number]) : [],
            'canImport' => $user->hasAdminCapability('can_manage_users'),
            'unassigned' => self::students()->whereDoesntHave('examGroups')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = Group::create($this->validated($request) + ['user_id' => $request->user()->id]);
        ActivityLog::record('exam.group_created', "أنشأ المجموعة \"{$group->name}\"");

        return redirect()->route('exams.manage.groups', ['group' => $group->id])->with('success', 'تم إنشاء المجموعة.');
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        $group->update($this->validated($request));

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroy(Request $request, Group $group): RedirectResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        // An exam left with no group would open to every student.
        if ($group->exams()->exists()) {
            return back()->withErrors(['group' => 'المجموعة معطاة لامتحانات ('.$group->exams()->count().'). أزلها من إعدادات تلك الامتحانات أولاً.']);
        }
        $group->delete();

        return redirect()->route('exams.manage.groups')->with('success', 'حُذفت المجموعة؛ الطلاب أنفسهم باقون.');
    }

    /** The students' accounts: not admins, teachers or invigilators. */
    private static function students(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->where('role', '!=', 'admin')->where('is_examiner', false)->where('is_invigilator', false);
    }

    /**
     * Students to add to a group: those in no group yet (or, with «others», those in other groups,
     * to move), found by name, e-mail or number; 60 at a time.
     */
    public function candidates(Request $request, Group $group): \Illuminate\Http\JsonResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        $q = trim((string) $request->query('q', ''));
        $others = $request->boolean('others');
        $query = self::students()->where('is_active', true)
            ->whereDoesntHave('examGroups', fn ($g) => $g->whereKey($group->id))
            ->when(! $others, fn ($u) => $u->whereDoesntHave('examGroups'))
            ->when($others, fn ($u) => $u->whereHas('examGroups'))
            ->when($q !== '', fn ($u) => $u->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('student_number', 'like', "%{$q}%")));
        $total = (clone $query)->count();
        $page = $query->with('examGroups:exam_groups.id,name')->orderBy('name')->offset(max(0, $request->integer('offset')))->limit(60)
            ->get(['users.id', 'name', 'email', 'student_number']);

        return response()->json([
            'total' => $total,
            'students' => $page->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'number' => $u->student_number,
                'email' => str_ends_with((string) $u->email, '@students.invalid') ? null : $u->email,
                'group' => $u->examGroups->pluck('name')->implode('، ') ?: null,
            ])->values(),
        ]);
    }

    /** Takes the chosen students out of the group (their accounts stay). */
    public function removeMembers(Request $request, Group $group): RedirectResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        $ids = $request->validate(['ids' => ['required', 'array', 'max:5000'], 'ids.*' => ['integer']])['ids'];
        $n = $group->members()->detach($ids);

        return back()->with('success', 'أُزيل '.$n.' طالباً من المجموعة.');
    }

    /**
     * Adds students: chosen from the list («ids»), or by e-mail or student number, one per line
     * (or separated by commas). A student in another group is moved only with «move»; otherwise
     * they are left where they are and named in the answer.
     */
    public function addMembers(Request $request, Group $group): RedirectResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        if ($request->has('ids')) {
            $ids = $request->validate(['ids' => ['required', 'array', 'max:5000'], 'ids.*' => ['integer']])['ids'];

            return $this->join($group, User::whereIn('id', $ids)->get(['id', 'name']), $request->boolean('move'), collect());
        }
        // E-mails compared in lower case; student numbers as written.
        $emails = collect(preg_split('/[\s,،;]+/u', (string) $request->input('emails'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($v) => str_contains($v, '@') ? mb_strtolower($v) : $v)->unique();
        if ($emails->isEmpty()) {
            throw ValidationException::withMessages(['emails' => 'اكتب بريد طالب أو رقمه الجامعي على الأقل.']);
        }
        $found = User::whereIn('email', $emails)->orWhereIn('student_number', $emails)->get(['id', 'name', 'email', 'student_number']);
        $known = $found->pluck('email')->concat($found->pluck('student_number')->filter());

        return $this->join($group, $found, $request->boolean('move'), $emails->diff($known));
    }

    /** Puts the students in the group: one group each, so those in another one move only when asked. */
    private function join(Group $group, \Illuminate\Support\Collection $users, bool $move, \Illuminate\Support\Collection $missing): RedirectResponse
    {
        $elsewhere = \Illuminate\Support\Facades\DB::table('exam_group_user')->whereIn('user_id', $users->pluck('id'))->where('exam_group_id', '!=', $group->id)->pluck('user_id')->unique();
        $add = $move ? $users : $users->reject(fn ($u) => $elsewhere->contains($u->id));
        \Illuminate\Support\Facades\DB::transaction(function () use ($group, $add, $move, $elsewhere) {
            if ($move && $elsewhere->isNotEmpty()) {
                \Illuminate\Support\Facades\DB::table('exam_group_user')->whereIn('user_id', $elsewhere)->where('exam_group_id', '!=', $group->id)->delete();
            }
            $group->members()->syncWithoutDetaching($add->pluck('id')->all());
        });
        $kept = $move ? collect() : $users->filter(fn ($u) => $elsewhere->contains($u->id));
        ActivityLog::record('exam.group_members', 'أضاف '.$add->count()." طالباً إلى المجموعة \"{$group->name}\"");

        return back()->with('success', 'أُضيف '.$add->count().' طالباً.'
            .($move && $elsewhere->isNotEmpty() ? ' نُقل '.$elsewhere->count().' من مجموعات أخرى.' : '')
            .($kept->isNotEmpty() ? ' في مجموعة أخرى (لم يُضافوا): '.$kept->pluck('name')->implode('، ').'.' : '')
            .($missing->isNotEmpty() ? ' لا حساب لـ: '.$missing->implode('، ') : ''));
    }

    public function removeMember(Request $request, Group $group, User $user): RedirectResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        $group->members()->detach($user->id);

        return back()->with('success', 'أُزيل الطالب من المجموعة.');
    }

    /**
     * Login cards for the hall: every student in the group gets a new password, printed once
     * on a card with the address and their e-mail. Teachers and admins in the group are left alone.
     */
    public function cards(Request $request, Group $group): Response
    {
        abort_unless($group->isEditableBy($request->user()) && $request->user()->hasAdminCapability('can_manage_users'), 404);
        $cards = $group->members()->where('role', '!=', 'admin')->where('is_examiner', false)->orderBy('name')->get()
            ->map(function (User $u) {
                $password = strtolower(\Illuminate\Support\Str::password(8, symbols: false));
                $u->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($password)])->save();

                return ['name' => $u->name, 'email' => str_ends_with($u->email, '@students.invalid') ? null : $u->email, 'student_number' => $u->student_number, 'password' => $password,
                    // The QR code: this address signs the student in (and marks them present when the invigilator scans it).
                    'card_url' => route('card.login', $u->renewCardCode())];
            });
        ActivityLog::record('exam.cards', "أعاد كلمات مرور {$cards->count()} طالباً وطبع بطاقات دخول للمجموعة \"{$group->name}\"");

        return Inertia::render('Exams/Manage/Cards', ['group' => $group->name, 'cards' => $cards, 'address' => rtrim(config('app.url'), '/'), 'site' => config('app.name')]);
    }

    /** The group's gradebook: each student's counted mark in each exam given to the group (or as CSV). */
    public function gradebook(Request $request, Group $group): Response|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()->canManageExams(), 403);
        $exams = $group->exams()->with('questions')->orderBy('opens_at')->orderBy('id')->get();
        $students = $group->members()->orderBy('name')->get(['users.id', 'name', 'email']);
        $rows = $students->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'marks' => $exams->map(function ($e) use ($u) {
                $score = $e->finalScoreFor($u);
                $max = $e->maxScore();

                return ['score' => $score, 'percent' => $score !== null && $max > 0 ? round($score / $max * 100, 1) : null];
            })->values(),
        ])->map(fn ($r) => $r + ['average' => collect($r['marks'])->pluck('percent')->filter(fn ($p) => $p !== null)->avg()])->values();
        $columns = $exams->map(fn ($e) => ['id' => $e->id, 'title' => $e->text('title'), 'max' => $e->maxScore(), 'pass_mark' => $e->pass_mark])->values();

        if ($request->boolean('csv')) {
            return response()->streamDownload(function () use ($rows, $columns) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_merge(['الطالب', 'البريد'], $columns->map(fn ($c) => $c['title'].' (/'.$c['max'].')')->all(), ['المعدّل %']));
                foreach ($rows as $r) {
                    fputcsv($out, array_merge([$r['name'], $r['email']], collect($r['marks'])->map(fn ($m) => $m['score'] ?? '')->all(), [$r['average'] !== null ? round($r['average'], 1) : '']));
                }
                fclose($out);
            }, 'gradebook-'.\Illuminate\Support\Str::slug($group->name ?: 'group').'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return Inertia::render('Exams/Manage/Gradebook', ['group' => ['id' => $group->id, 'name' => $group->name, 'stage' => $group->stage], 'columns' => $columns, 'rows' => $rows]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'stage' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
