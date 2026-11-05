<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json(['user' => new UserResource($user)]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Locales::active()))],
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json(['user' => new UserResource($user)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        $user->update(['password' => Hash::make($data['password'])]);

        // A stolen token used from another device would otherwise stay
        // valid forever, even after the user reacts to a compromise by
        // changing their password. The token making *this* request is
        // deliberately kept alive so the client that just performed the
        // change isn't logged out by its own request.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        event(new PasswordResetEvent($user));

        return response()->json(['message' => 'تم تحديث كلمة المرور.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password:sanctum'],
        ]);

        $user = $request->user();
        $user->tokens()->delete();
        // Rows cascade-delete at the DB level, but the actual bytes on
        // disk don't — matches the cleanup admin-initiated deletion
        // already does (Api\V1\Admin\UserController::destroy).
        Storage::disk(config('filenest.disk'))->deleteDirectory((string) $user->id);
        $user->delete();

        return response()->json(['message' => 'تم حذف الحساب.']);
    }
}
