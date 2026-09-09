<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Services\ZipDownloadBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicShareController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $share = Share::where('token', $token)->firstOrFail();
        $unlocked = $this->isUnlocked($request, $share);

        return Inertia::render('Share/Show', [
            'token' => $token,
            'name' => $share->shareable?->name,
            'isFolder' => $share->shareable_type === 'folder',
            'size' => $share->shareable_type === 'file' ? $share->shareable?->human_size : null,
            'requiresPassword' => $share->isPasswordProtected() && ! $unlocked,
            'expired' => $share->isExpired(),
            'limitReached' => $share->isDownloadLimitReached(),
            'allowDownload' => $share->allow_download,
            'unlocked' => $unlocked,
        ]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $share = Share::where('token', $token)->firstOrFail();

        $data = $request->validate(['password' => ['required', 'string']]);

        if (! $share->isPasswordProtected() || ! Hash::check($data['password'], $share->password)) {
            return back()->withErrors(['password' => 'كلمة المرور غير صحيحة.']);
        }

        $request->session()->put("share_unlocked.{$share->id}", true);

        return redirect()->route('share.public.show', $token);
    }

    public function download(Request $request, string $token, ZipDownloadBuilder $builder): BinaryFileResponse|HttpResponse
    {
        $share = Share::where('token', $token)->firstOrFail();

        abort_if($share->isExpired(), 410, 'انتهت صلاحية رابط المشاركة.');
        abort_if($share->isDownloadLimitReached(), 410, 'تم الوصول إلى الحد الأقصى لعدد مرات التنزيل.');
        abort_unless($share->allow_download, 403, 'التنزيل غير مفعّل لهذه المشاركة.');
        abort_unless($this->isUnlocked($request, $share), 403, 'هذه المشاركة محمية بكلمة مرور.');

        $share->increment('download_count');

        if ($share->shareable_type === 'file') {
            /** @var File $file */
            $file = $share->shareable;
            abort_unless($file, 404);

            return response()->download($file->disk()->path($file->path), $file->name);
        }

        /** @var Folder $folder */
        $folder = $share->shareable;
        abort_unless($folder, 404);

        $zipPath = $builder->build(collect(), collect([$folder]));

        return response()->download($zipPath, $folder->name.'.zip')->deleteFileAfterSend();
    }

    private function isUnlocked(Request $request, Share $share): bool
    {
        return ! $share->isPasswordProtected() || $request->session()->get("share_unlocked.{$share->id}", false);
    }
}
