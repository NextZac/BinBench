<?php

namespace App\Policies;

use App\Models\MapDefinition;
use App\Models\User;

/**
 * Imported definitions (XDFs) belong to the user who imported them.
 */
class MapDefinitionPolicy
{
    public function delete(User $user, MapDefinition $mapDefinition): bool
    {
        return $mapDefinition->user_id === $user->id;
    }
}
