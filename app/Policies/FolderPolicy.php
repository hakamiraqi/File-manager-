<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

class FolderPolicy
{
    public function view(User $user, Folder $folder): bool
    {
        return $folder->isAccessibleBy($user);
    }

    public function update(User $user, Folder $folder): bool
    {
        return $this->view($user, $folder);
    }

    public function delete(User $user, Folder $folder): bool
    {
        return $this->view($user, $folder);
    }
}
