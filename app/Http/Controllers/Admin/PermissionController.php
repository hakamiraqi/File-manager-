<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RolePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Permissions', [
            'matrix' => RolePermission::matrix(),
            'roles' => RolePermission::ROLES,
            'actions' => RolePermission::ACTIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'matrix' => ['required', 'array'],
        ]);

        foreach ($data['matrix'] as $role => $actions) {
            if (! in_array($role, RolePermission::ROLES, true)) {
                continue;
            }

            foreach ($actions as $action => $allowed) {
                if (! in_array($action, RolePermission::ACTIONS, true)) {
                    continue;
                }

                RolePermission::updateOrCreate(
                    ['role' => $role, 'action' => $action],
                    ['allowed' => (bool) $allowed],
                );
            }
        }

        ActivityLog::record('admin.permissions_updated', 'حدّث مصفوفة صلاحيات الأدوار');

        return back()->with('success', 'تم حفظ الصلاحيات.');
    }
}
