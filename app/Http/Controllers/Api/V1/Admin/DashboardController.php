<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'stats' => [
                'users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'files' => File::whereNull('deleted_at')->count(),
                'folders' => Folder::whereNull('deleted_at')->count(),
                'shares' => Share::count(),
                'storage_used' => (int) User::sum('storage_used_bytes'),
            ],
            'recent_activity' => ActivityLog::with('user:id,name')->latest('created_at')->limit(15)->get(),
            'top_users' => User::orderByDesc('storage_used_bytes')->limit(5)->get(['id', 'name', 'email', 'storage_used_bytes', 'storage_quota_bytes']),
        ]);
    }
}
