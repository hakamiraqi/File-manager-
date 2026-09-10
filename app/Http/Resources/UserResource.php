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
            // Per-role capabilities (RolePermission), not admin-only — the
            // same values HandleInertiaRequests exposes to the web app as
            // permissions.manage_questions / permissions.comment, so the
            // mobile app can hide the question-authoring UI and comment
            // box for a role that lacks them, exactly like the web player.
            'permissions' => [
                'manage_questions' => $this->canPerform('manage_questions'),
                'comment' => $this->canPerform('comment'),
            ],
            'is_admin' => $this->isAdmin(),
            'admin' => $this->isAdmin() ? [
                'appearance' => $this->hasAdminCapability('can_manage_appearance'),
                'users' => $this->hasAdminCapability('can_manage_users'),
                'translations' => $this->hasAdminCapability('can_manage_translations'),
                'statistics' => $this->hasAdminCapability('can_view_statistics'),
                'settings' => $this->hasAdminCapability('can_manage_settings'),
                'settings_page' => $this->hasAdminCapability('can_manage_settings')
                    || $this->hasAdminCapability('can_manage_appearance'),
            ] : null,
        ];
    }
}
