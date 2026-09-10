<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => (bool) $this->is_active,
            'is_master_admin' => (bool) $this->is_master_admin,
            'storage_used_bytes' => $this->storage_used_bytes,
            'storage_quota_bytes' => $this->quotaBytes(),
            'can_manage_appearance' => (bool) $this->can_manage_appearance,
            'can_manage_users' => (bool) $this->can_manage_users,
            'can_manage_translations' => (bool) $this->can_manage_translations,
            'can_view_statistics' => (bool) $this->can_view_statistics,
            'can_manage_settings' => (bool) $this->can_manage_settings,
            'created_at' => $this->created_at,
        ];
    }
}
