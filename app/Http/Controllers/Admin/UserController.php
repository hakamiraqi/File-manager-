<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('q'));

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => ['q' => $search],
        ]);
    }

    private const CAPABILITY_FIELDS = [
        'can_manage_appearance', 'can_manage_users', 'can_manage_translations',
        'can_view_statistics', 'can_manage_settings',
    ];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'in:admin,editor,contributor,user'],
            'storage_quota_mb' => ['nullable', 'integer', 'min:1'],
            'can_manage_appearance' => ['boolean'],
            'can_manage_users' => ['boolean'],
            'can_manage_translations' => ['boolean'],
            'can_view_statistics' => ['boolean'],
            'can_manage_settings' => ['boolean'],
        ]);

        // Only the master admin may create another admin at all: the
        // "admin" role itself bypasses every per-file ownership check
        // (FilePolicy/FolderPolicy let any admin touch any user's files
        // regardless of capability flags), so letting a merely
        // "manage-users" sub-admin mint a peer admin would hand that new
        // account full cross-user file access no matter which capability
        // fields get stripped below.
        abort_if($data['role'] === 'admin' && ! $request->user()->is_master_admin, 403, 'إنشاء حساب مشرف يتطلب صلاحية المدير الرئيسي.');

        if (! $request->user()->is_master_admin) {
            foreach (self::CAPABILITY_FIELDS as $field) {
                unset($data[$field]);
            }
        }

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'storage_quota_bytes' => isset($data['storage_quota_mb']) ? $data['storage_quota_mb'] * 1024 * 1024 : null,
            'email_verified_at' => now(),
            ...array_intersect_key($data, array_flip(self::CAPABILITY_FIELDS)),
        ]);

        ActivityLog::record('admin.user_created', "أنشأ المستخدم \"{$data['name']}\"");

        return back()->with('success', 'تم إنشاء المستخدم.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_master_admin && ! $request->user()->is_master_admin, 403, 'لا يمكن تعديل حساب المدير الرئيسي.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,'.$user->id],
            'role' => ['sometimes', 'in:admin,editor,contributor,user'],
            'is_active' => ['sometimes', 'boolean'],
            'storage_quota_mb' => ['nullable', 'integer', 'min:1'],
            'password' => ['nullable', Password::defaults()],
            'can_manage_appearance' => ['boolean'],
            'can_manage_users' => ['boolean'],
            'can_manage_translations' => ['boolean'],
            'can_view_statistics' => ['boolean'],
            'can_manage_settings' => ['boolean'],
        ]);

        // Same rule as store(): promoting anyone to "admin" bypasses every
        // per-file ownership check regardless of capability flags, so only
        // the master admin may do it.
        abort_if(($data['role'] ?? null) === 'admin' && $user->role !== 'admin' && ! $request->user()->is_master_admin, 403, 'ترقية مستخدم إلى مشرف يتطلب صلاحية المدير الرئيسي.');

        if (! $request->user()->is_master_admin) {
            foreach (self::CAPABILITY_FIELDS as $field) {
                unset($data[$field]);
            }
        }

        if (array_key_exists('storage_quota_mb', $data)) {
            $data['storage_quota_bytes'] = $data['storage_quota_mb'] ? $data['storage_quota_mb'] * 1024 * 1024 : null;
            unset($data['storage_quota_mb']);
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record('admin.user_updated', "حدّث بيانات المستخدم \"{$user->name}\"");

        return back()->with('success', 'تم تحديث بيانات المستخدم.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'لا يمكنك حذف حسابك الخاص.');
        abort_if($user->is_master_admin, 403, 'لا يمكن حذف حساب المدير الرئيسي.');

        Storage::disk(config('filenest.disk'))->deleteDirectory((string) $user->id);
        $user->delete();

        ActivityLog::record('admin.user_deleted', "حذف المستخدم \"{$user->name}\"");

        return back()->with('success', 'تم حذف المستخدم وجميع ملفاته.');
    }
}
