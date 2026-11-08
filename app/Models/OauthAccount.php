<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'provider', 'provider_id', 'avatar_url'])]
class OauthAccount extends Model
{
    public const PROVIDERS = ['google', 'github', 'linkedin'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
