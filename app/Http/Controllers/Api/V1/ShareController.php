<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShareResource;
use App\Mail\ShareNotificationMail;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\ShareEmail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class ShareController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shares = Share::with('shareable')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['shares' => ShareResource::collection($shares)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->canPerform('share')) {
            throw new AuthorizationException('لا تملك صلاحية مشاركة الملفات.');
        }

        $data = $request->validate([
            'shareable_type' => ['required', 'in:file,folder'],
            'shareable_id' => ['required', 'integer'],
            'password' => ['nullable', 'string', 'min:4'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_downloads' => ['nullable', 'integer', 'min:1'],
            'one_time_download' => ['boolean'],
            'allow_download' => ['boolean'],
        ]);

        $shareable = $this->resolveShareable($request, $data['shareable_type'], $data['shareable_id']);
        $this->authorize('view', $shareable);

        $oneTime = $data['one_time_download'] ?? false;

        $share = Share::create([
            'user_id' => $request->user()->id,
            'shareable_type' => $shareable->getMorphClass(),
            'shareable_id' => $shareable->id,
            'password' => isset($data['password']) ? Hash::make($data['password']) : null,
            'expires_at' => $data['expires_at'] ?? null,
            'max_downloads' => $oneTime ? 1 : ($data['max_downloads'] ?? null),
            'one_time_download' => $oneTime,
            'allow_download' => $data['allow_download'] ?? true,
        ]);

        ActivityLog::record('share.created', "أنشأ رابط مشاركة لـ \"{$shareable->name}\"", $share);

        return response()->json(['share' => new ShareResource($share->load('shareable'))], 201);
    }

    public function update(Request $request, Share $share): JsonResponse
    {
        $this->authorize('update', $share);

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:4'],
            'remove_password' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_downloads' => ['nullable', 'integer', 'min:1'],
            'one_time_download' => ['boolean'],
            'allow_download' => ['boolean'],
        ]);

        if (! empty($data['remove_password'])) {
            $share->password = null;
        } elseif (! empty($data['password'])) {
            $share->password = Hash::make($data['password']);
        }

        if (! empty($data['one_time_download'])) {
            $data['max_downloads'] = 1;
        }

        $share->fill($request->only(['expires_at', 'max_downloads', 'one_time_download', 'allow_download']))->save();

        return response()->json(['share' => new ShareResource($share->load('shareable'))]);
    }

    public function destroy(Request $request, Share $share): JsonResponse
    {
        $this->authorize('delete', $share);

        $share->delete();

        ActivityLog::record('share.revoked', 'ألغى رابط مشاركة');

        return response()->json(['message' => 'تم إلغاء رابط المشاركة.']);
    }

    public function sendEmail(Request $request, Share $share): JsonResponse
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

        return response()->json(['message' => 'تم إرسال رابط المشاركة بالبريد الإلكتروني.']);
    }

    private function resolveShareable(Request $request, string $type, int $id): File|Folder
    {
        return $type === 'file'
            ? File::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($id)
            : Folder::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($id);
    }
}
