<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Setting;
use App\Services\RemoteUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemoteUploadController extends Controller
{
    public function store(Request $request, RemoteUploadService $service): JsonResponse
    {
        if (! Setting::get('remote_upload_enabled', false)) {
            abort(404);
        }

        if (! $request->user()->canPerform('upload')) {
            throw new AuthorizationException('لا تملك صلاحية رفع الملفات.');
        }

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        if ($data['folder_id'] ?? null) {
            Folder::findAccessible($request->user(), $data['folder_id']);
        }

        $file = $service->download($request->user(), $data['url'], $data['folder_id'] ?? null);

        return response()->json(['file' => $file]);
    }
}
