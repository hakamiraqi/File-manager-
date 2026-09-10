<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RolePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'matrix' => RolePermission::matrix(),
            'roles' => RolePermission::ROLES,
            'actions' => RolePermission::ACTIONS,
        ]);
    }

    public function update(Request $request): JsonResponse
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

        return response()->json(['matrix' => RolePermission::matrix()]);
    }
}
