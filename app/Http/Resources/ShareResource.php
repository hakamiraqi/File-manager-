<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Share
 */
class ShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $usePretty = (bool) \App\Models\Setting::get('enable_pretty_links', false);

        return [
            'id' => $this->id,
            'shareable_type' => $this->shareable instanceof \App\Models\Folder ? 'folder' : 'file',
            'shareable_id' => $this->shareable_id,
            'shareable_name' => $this->shareable?->name,
            'url' => $usePretty
                ? route('share.public.short', $this->short_code)
                : route('share.public.show', $this->token),
            'is_password_protected' => $this->isPasswordProtected(),
            'allow_download' => (bool) $this->allow_download,
            'one_time_download' => (bool) $this->one_time_download,
            'max_downloads' => $this->max_downloads,
            'download_count' => $this->download_count,
            'expires_at' => $this->expires_at,
            'is_expired' => $this->isExpired(),
            'is_revoked' => $this->isRevoked(),
            'created_at' => $this->created_at,
        ];
    }
}
