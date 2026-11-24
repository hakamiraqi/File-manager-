<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Services\NotificationDispatcher;
use App\Services\ZipExtractor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «استخراج هنا»: unpacks a .zip already in the file manager into a new
 * folder beside it. The archive itself is kept.
 */
class ZipExtractController extends Controller
{
    public function store(Request $request, File $file, ZipExtractor $extractor, NotificationDispatcher $notifier): JsonResponse
    {
        $this->authorize('view', $file);

        $user = $request->user();
        if (! $user->canPerform('upload')) {
            throw new AuthorizationException('لا تملك صلاحية رفع الملفات.');
        }

        if (! ZipExtractor::isZip($file->name) && strtolower((string) $file->extension) !== 'zip') {
            throw ValidationException::withMessages(['file' => 'هذا الملف ليس ملفاً مضغوطاً بصيغة zip.']);
        }

        if ($file->folder_id) {
            Folder::findAccessible($user, $file->folder_id);
        }

        $path = $file->disk()->path($file->path);
        if (! is_file($path)) {
            throw ValidationException::withMessages(['file' => 'الملف غير موجود على الخادم.']);
        }

        $result = $extractor->extract($path, $file->name, $user, $file->folder_id);

        ActivityLog::record('file.extracted', "استخراج الملف المضغوط \"{$file->name}\"", $result['folder']);
        $notifier->uploadOccurred($user, $result['folder']->name);

        return response()->json(['extracted' => FileUploadController::summary($result)]);
    }
}
