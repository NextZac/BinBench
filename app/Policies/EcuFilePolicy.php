<?php

namespace App\Policies;

use App\Models\EcuFile;
use App\Models\User;

/**
 * Files are private: only the user who uploaded one can see or change it.
 */
class EcuFilePolicy
{
    public function view(User $user, EcuFile $ecuFile): bool
    {
        return $ecuFile->user_id === $user->id;
    }

    public function update(User $user, EcuFile $ecuFile): bool
    {
        return $ecuFile->user_id === $user->id;
    }

    public function delete(User $user, EcuFile $ecuFile): bool
    {
        return $ecuFile->user_id === $user->id;
    }
}
