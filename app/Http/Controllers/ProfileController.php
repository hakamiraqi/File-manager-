<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'stats' => [
                'files' => $user->files()->count(),
                'folders' => $user->folders()->count(),
                'storage_used' => (int) $user->storage_used_bytes,
                'storage_total' => $user->quotaBytes(),
                'member_since' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'activity_30d' => \App\Models\ActivityLog::where('user_id', $user->id)
                    ->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Save a new profile picture. The page crops it to a square in the
     * browser; here it is checked, shrunk to 512px when GD is available,
     * and the old picture is removed.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ]);

        $user = $request->user();
        $file = $request->file('avatar');
        $path = 'avatars/'.$user->id.'-'.Str::random(10).'.jpg';
        $disk = Storage::disk('public');

        $jpeg = $this->squareJpeg($file->getRealPath(), 512);
        if ($jpeg !== null) {
            $disk->put($path, $jpeg);
        } else {
            $path = $file->storeAs('avatars', $user->id.'-'.Str::random(10).'.'.$file->extension(), 'public');
        }

        if ($user->avatar_path && $user->avatar_path !== $path) {
            $disk->delete($user->avatar_path);
        }
        $user->forceFill(['avatar_path' => $path])->save();

        return back()->with('success', 'تم تحديث الصورة الشخصية.');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return back()->with('success', 'تم حذف الصورة الشخصية.');
    }

    /** Centre-crops an image to a square of $size px as JPEG; null without GD. */
    private function squareJpeg(string $source, int $size): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            return null;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $target = min($size, $side);
        $out = imagecreatetruecolor($target, $target);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $target, $target, $side, $side);
        ob_start();
        imagejpeg($out, null, 88);
        $data = ob_get_clean();
        imagedestroy($image);
        imagedestroy($out);

        return $data ?: null;
    }

    /**
     * Update the user's interface preferences (language).
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Locales::active()))],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'تم حفظ التفضيلات.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        // Rows cascade-delete at the DB level, but the actual bytes on
        // disk don't — without this, self-deleting an account (unlike
        // admin-initiated deletion, which already does this) would leak
        // the user's storage directory forever.
        Storage::disk(config('filenest.disk'))->deleteDirectory((string) $user->id);
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
