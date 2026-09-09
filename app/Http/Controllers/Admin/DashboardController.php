<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
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
                'files' => File::whereNull('deleted_at')->count(),
                'folders' => Folder::whereNull('deleted_at')->count(),
                'shares' => Share::count(),
                'storage_used' => (int) User::sum('storage_used_bytes'),
            ],
            'recentActivity' => ActivityLog::with('user:id,name')->latest('created_at')->limit(15)->get(),
            'topUsers' => User::orderByDesc('storage_used_bytes')->limit(5)->get(['id', 'name', 'email', 'storage_used_bytes', 'storage_quota_bytes']),
        ]);
    }
}
