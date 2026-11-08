<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'examiners' => User::where('is_examiner', true)->count(),
                'exams' => Exam::count(),
                'published' => Exam::where('is_published', true)->count(),
                'attempts' => Attempt::whereNotNull('submitted_at')->count(),
                'pending' => Attempt::where('needs_grading', true)->count(),
                'running' => Attempt::whereNull('submitted_at')->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>', now()))->count(),
            ],
            'recentActivity' => ActivityLog::with('user:id,name,avatar_path')->latest('created_at')->latest('id')->limit(12)->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'label' => ActivityLogController::LABELS[$log->action] ?? $log->action,
                    'description' => $log->description,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'user' => $log->user ? ['name' => $log->user->name, 'avatar_url' => $log->user->avatar_url] : null,
                ]),
            'topExams' => Exam::withCount(['attempts' => fn ($q) => $q->whereNotNull('submitted_at')])
                ->orderByDesc('attempts_count')->limit(5)->get()
                ->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->text('title'), 'attempts' => $e->attempts_count]),
        ]);
    }
}
