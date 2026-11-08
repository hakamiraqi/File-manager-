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
        $role = in_array($request->query('role'), User::ROLES, true) ? $request->query('role') : null;
        $status = in_array($request->query('status'), ['active', 'suspended'], true) ? $request->query('status') : null;

        $users = User::query()
            // Grouped, so the role/status filters apply to both name and email matches.
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($role, fn ($q) => $q->where('role', $role))
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => ['q' => $search, 'role' => $role, 'status' => $status],
            'roleCounts' => User::query()->selectRaw('role, COUNT(*) as c')->groupBy('role')->pluck('c', 'role'),
            'statusCounts' => [
                'active' => User::where('is_active', true)->count(),
                'suspended' => User::where('is_active', false)->count(),
            ],
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
            'role' => ['required', 'in:admin,user'],
            'is_active' => ['sometimes', 'boolean'],
            'is_examiner' => ['sometimes', 'boolean'],
            'is_invigilator' => ['sometimes', 'boolean'],
            'exam_time_multiplier' => ['sometimes', 'numeric', 'min:1', 'max:4'],
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

        $created = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_examiner' => (bool) ($data['is_examiner'] ?? false),
            'is_invigilator' => (bool) ($data['is_invigilator'] ?? false),
            'exam_time_multiplier' => $data['exam_time_multiplier'] ?? 1,
            'is_active' => (bool) ($data['is_active'] ?? true),
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
            'role' => ['sometimes', 'in:admin,user'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', Password::defaults()],
            'is_examiner' => ['sometimes', 'boolean'],
            'is_invigilator' => ['sometimes', 'boolean'],
            'exam_time_multiplier' => ['sometimes', 'numeric', 'min:1', 'max:4'],
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
        $this->guardDeactivation($request, $user, $data['is_active'] ?? null);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record('admin.user_updated', "حدّث بيانات المستخدم \"{$user->name}\"");

        return back()->with('success', 'تم تحديث بيانات المستخدم.');
    }

    /** Active / inactive switch on the users list. */
    public function status(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_master_admin && ! $request->user()->is_master_admin, 403, 'لا يمكن تعديل حساب المدير الرئيسي.');
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $this->guardDeactivation($request, $user, $active);

        $user->update(['is_active' => $active]);
        if (! $active) {
            // Signed-in phones lose access at once.
            $user->tokens()->delete();
        }
        ActivityLog::record('admin.user_updated', ($active ? 'فعّل' : 'أوقف')." حساب المستخدم \"{$user->name}\"");

        return back()->with('success', $active ? 'تم تفعيل الحساب.' : 'تم إيقاف الحساب.');
    }

    /** Nobody switches off their own account or the master admin's. */
    private function guardDeactivation(Request $request, User $user, $active): void
    {
        if ($active === null || (bool) $active) {
            return;
        }
        abort_if($user->id === $request->user()->id, 422, 'لا يمكنك إيقاف حسابك الخاص.');
        abort_if($user->is_master_admin, 422, 'لا يمكن إيقاف حساب المدير الرئيسي.');
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
