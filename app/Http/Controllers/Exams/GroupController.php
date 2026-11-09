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

/** Classes and sections: who is in them, and so who sees an exam given to them. */
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
            'members' => $current ? $current->members()->orderBy('name')->get(['users.id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]) : [],
            'canImport' => $user->hasAdminCapability('can_manage_users'),
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

    /** Adds students by e-mail or student number, one per line (or separated by commas). */
    public function addMembers(Request $request, Group $group): RedirectResponse
    {
        abort_unless($group->isEditableBy($request->user()), 404);
        // E-mails compared in lower case; student numbers as written.
        $emails = collect(preg_split('/[\s,،;]+/u', (string) $request->input('emails'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($v) => str_contains($v, '@') ? mb_strtolower($v) : $v)->unique();
        if ($emails->isEmpty()) {
            throw ValidationException::withMessages(['emails' => 'اكتب بريد طالب أو رقمه الجامعي على الأقل.']);
        }
        $found = User::whereIn('email', $emails)->orWhereIn('student_number', $emails)->get(['id', 'email', 'student_number']);
        $users = $found->pluck('id', 'id');
        $group->members()->syncWithoutDetaching($users->values());
        $known = $found->pluck('email')->concat($found->pluck('student_number')->filter());
        $missing = $emails->diff($known);

        return back()->with('success', 'أُضيف '.$users->count().' طالباً.'.($missing->isNotEmpty() ? ' لا حساب لـ: '.$missing->implode('، ') : ''));
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

                return ['name' => $u->name, 'email' => str_ends_with($u->email, '@students.invalid') ? null : $u->email, 'student_number' => $u->student_number, 'password' => $password];
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
            'name' => $u->name, 'email' => $u->email,
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
