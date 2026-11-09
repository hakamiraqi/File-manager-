<?php

namespace App\Http\Controllers;

use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «بحث سريع» (Ctrl+K, 2026-11-09, by request): exams, students, groups and hall computers
 * from any page, each only for those allowed to see it, with where to go.
 */
class QuickSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $out = [];
        // Exam titles are kept in every language (JSON): matched here, ignoring hamzas and case.
        $needle = \App\Services\Exams\Grader::normalize($q);
        $titled = fn ($query) => $query->latest('id')->limit(800)->get()
            ->filter(fn (Exam $e) => str_contains(\App\Services\Exams\Grader::normalize(implode(' ', (array) $e->title).' '.$e->subject), $needle));

        if ($user->canManageExams()) {
            $exams = $titled(Exam::query()->when(! $user->isAdmin(), fn ($e) => $e->where('user_id', $user->id)))->take(6);
            foreach ($exams as $e) {
                $out[] = ['kind' => 'exam', 'title' => $e->text('title'), 'sub' => trim(($e->subject ?? '').' · '.($e->is_published ? __('منشور') : __('غير منشور')), ' ·'), 'url' => route('exams.manage.edit', $e)];
            }
            $students = User::query()->where('role', '!=', 'admin')->where('is_examiner', false)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('student_number', 'like', $like))
                ->with('examGroups:exam_groups.id,name')->orderBy('name')->limit(8)->get(['users.id', 'name', 'email', 'student_number']);
            foreach ($students as $s) {
                $g = $s->examGroups->first();
                $out[] = ['kind' => 'student', 'title' => $s->name,
                    'sub' => collect([$s->student_number, str_ends_with((string) $s->email, '@students.invalid') ? null : $s->email, $g?->name])->filter()->implode(' · '),
                    'url' => $user->isAdmin() || ! $g ? route('exams.manage.students.report', $s->id) : route('exams.manage.groups', ['group' => $g->id])];
            }
            $groups = Group::withCount('members')->orderBy('name')->get()->filter(fn (Group $g) => str_contains(\App\Services\Exams\Grader::normalize($g->name.' '.$g->stage.' '.$g->department), $needle))->take(5);
            foreach ($groups as $g) {
                $out[] = ['kind' => 'group', 'title' => $g->name, 'sub' => collect([$g->stage, $g->department, $g->members_count.' '.__('طالب')])->filter()->implode(' · '), 'url' => route('exams.manage.groups', ['group' => $g->id])];
            }
        } else {
            // A student finds their own exams.
            $mine = $titled(Exam::where('is_published', true))->filter(fn (Exam $e) => $e->isAssignedTo($user))->take(6);
            foreach ($mine as $e) {
                $out[] = ['kind' => 'exam', 'title' => $e->text('title'), 'sub' => $e->subject, 'url' => route('exams.show', $e)];
            }
        }
        if ($user->canInvigilate()) {
            foreach (Computer::where(fn ($w) => $w->where('label', 'like', $like)->orWhere('ip', 'like', $like)->orWhere('room', 'like', $like))->orderBy('label')->limit(6)->get() as $pc) {
                $out[] = ['kind' => 'computer', 'title' => $pc->label, 'sub' => collect([$pc->room, $pc->ip])->filter()->implode(' · '), 'url' => route('exams.monitor.halls', ['pc' => $pc->id])];
            }
        }

        return response()->json(['results' => $out]);
    }
}
