<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Models\Share;
use App\Services\NotificationDispatcher;
use App\Services\ZipDownloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Anonymous access to a share link, mirroring web's PublicShareController.
 * The web version "unlocks" a password-protected share into the browser
 * session; there is no session here, so instead the password is supplied
 * on every protected call (metadata is never itself secret — only
 * download is gated, exactly as on the web).
 */
class PublicShareController extends Controller
{
    public function resolveShortCode(string $code): JsonResponse
    {
        $share = Share::where('short_code', $code)->firstOrFail();

        return response()->json(['token' => $share->token]);
    }

    public function show(string $token): JsonResponse
    {
        $share = Share::where('token', $token)->firstOrFail();

        return response()->json([
            'name' => $share->shareable?->name,
            'is_folder' => $share->shareable_type === 'folder',
            'size' => $share->shareable_type === 'file' ? $share->shareable?->human_size : null,
            'requires_password' => $share->isPasswordProtected(),
            'expired' => $share->isExpired(),
            'limit_reached' => $share->isDownloadLimitReached(),
            'allow_download' => (bool) $share->allow_download,
        ]);
    }

    public function download(Request $request, string $token, ZipDownloadBuilder $builder): BinaryFileResponse
    {
        $share = Share::where('token', $token)->firstOrFail();

        abort_if($share->isExpired(), 410, 'انتهت صلاحية رابط المشاركة.');
        abort_if($share->isDownloadLimitReached(), 410, 'تم الوصول إلى الحد الأقصى لعدد مرات التنزيل.');
        abort_unless($share->allow_download, 403, 'التنزيل غير مفعّل لهذه المشاركة.');

        if ($share->isPasswordProtected()) {
            $data = $request->validate(['password' => ['required', 'string']]);
            abort_unless(Hash::check($data['password'], $share->password), 403, 'كلمة المرور غير صحيحة.');
        }

        $share->increment('download_count');

        if ($share->one_time_download) {
            $share->update(['revoked_at' => now()]);
        }

        ActivityLog::record('share.downloaded', 'حمّل زائر عنصراً مشتركاً عبر رابط عام', $share);
        app(NotificationDispatcher::class)->downloadOccurred($share->user, (string) $share->shareable?->name);

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
}
