<?php

namespace App\Http\Controllers;

use App\Mail\ShareNotificationMail;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\ShareEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ShareController extends Controller
{
    public function index(Request $request): Response
    {
        $shares = Share::with('shareable')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return Inertia::render('FileManager/Shares', ['shares' => $shares]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shareable_type' => ['required', 'in:file,folder'],
            'shareable_id' => ['required', 'integer'],
            'password' => ['nullable', 'string', 'min:4'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_downloads' => ['nullable', 'integer', 'min:1'],
            'allow_download' => ['boolean'],
        ]);

        $shareable = $this->resolveShareable($request, $data['shareable_type'], $data['shareable_id']);
        $this->authorize('view', $shareable);

        $share = Share::create([
            'user_id' => $request->user()->id,
            'shareable_type' => $shareable->getMorphClass(),
            'shareable_id' => $shareable->id,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
            'expires_at' => $data['expires_at'] ?? null,
            'max_downloads' => $data['max_downloads'] ?? null,
            'allow_download' => $data['allow_download'] ?? true,
        ]);

        ActivityLog::record('share.created', "أنشأ رابط مشاركة لـ \"{$shareable->name}\"", $share);

        return back()
            ->with('success', 'تم إنشاء رابط المشاركة.')
            ->with('shareUrl', route('share.public.show', $share->token))
            ->with('shareId', $share->id);
    }

    public function update(Request $request, Share $share): RedirectResponse
    {
        $this->authorize('update', $share);

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:4'],
            'remove_password' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_downloads' => ['nullable', 'integer', 'min:1'],
            'allow_download' => ['boolean'],
        ]);

        if (! empty($data['remove_password'])) {
            $share->password = null;
        } elseif (! empty($data['password'])) {
            $share->password = Hash::make($data['password']);
        }

        $share->fill($request->only(['expires_at', 'max_downloads', 'allow_download']))->save();

        return back()->with('success', 'تم تحديث إعدادات المشاركة.');
    }

    public function destroy(Request $request, Share $share): RedirectResponse
    {
        $this->authorize('delete', $share);

        $share->delete();

        ActivityLog::record('share.revoked', 'ألغى رابط مشاركة');

        return back()->with('success', 'تم إلغاء رابط المشاركة.');
    }

    public function sendEmail(Request $request, Share $share): RedirectResponse
    {
        $this->authorize('view', $share);

        $data = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['email'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($data['emails'] as $email) {
            $shareEmail = ShareEmail::create([
                'share_id' => $share->id,
                'email' => $email,
                'message' => $data['message'] ?? null,
            ]);

            Mail::to($email)->queue(new ShareNotificationMail($share, $shareEmail));
            $shareEmail->update(['sent_at' => now()]);
        }

        ActivityLog::record('share.emailed', 'أرسل رابط مشاركة عبر البريد الإلكتروني إلى '.count($data['emails']).' مستلم');

        return back()->with('success', 'تم إرسال رابط المشاركة بالبريد الإلكتروني.');
    }

    private function resolveShareable(Request $request, string $type, int $id): File|Folder
    {
        return $type === 'file'
            ? File::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($id)
            : Folder::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($id);
    }
}
