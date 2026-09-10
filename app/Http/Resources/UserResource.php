<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'is_master_admin' => (bool) $this->is_master_admin,
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            'storage_used_bytes' => $this->storage_used_bytes,
            'storage_quota_bytes' => $this->quotaBytes(),
            'storage_usage_percent' => $this->storageUsagePercent(),
            'created_at' => $this->created_at,
        ];
    }
}
