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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'in:admin,user'],
            'storage_quota_mb' => ['nullable', 'integer', 'min:1'],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'storage_quota_bytes' => isset($data['storage_quota_mb']) ? $data['storage_quota_mb'] * 1024 * 1024 : null,
            'email_verified_at' => now(),
        ]);

        ActivityLog::record('admin.user_created', "أنشأ المستخدم \"{$data['name']}\"");

        return back()->with('success', 'تم إنشاء المستخدم.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,'.$user->id],
            'role' => ['sometimes', 'in:admin,user'],
            'is_active' => ['sometimes', 'boolean'],
            'storage_quota_mb' => ['nullable', 'integer', 'min:1'],
            'password' => ['nullable', Password::defaults()],
        ]);

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

        Storage::disk(config('filenest.disk'))->deleteDirectory((string) $user->id);
        $user->delete();

        ActivityLog::record('admin.user_deleted', "حذف المستخدم \"{$user->name}\"");

        return back()->with('success', 'تم حذف المستخدم وجميع ملفاته.');
    }
}
