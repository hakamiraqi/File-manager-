<?php

namespace App\Policies;

use App\Models\Share;
use App\Models\User;

class SharePolicy
{
    public function view(User $user, Share $share): bool
    {
        return $user->isAdmin() || $user->id === $share->user_id;
    }

    public function update(User $user, Share $share): bool
    {
        return $this->view($user, $share);
    }

    public function delete(User $user, Share $share): bool
    {
        return $this->view($user, $share);
    }
}
