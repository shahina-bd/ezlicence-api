<?php

namespace App\Policies;

use App\Models\InstructorProfile;
use App\Models\User;

class InstructorProfilePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, InstructorProfile $instructorProfile): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InstructorProfile $instructorProfile): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && (int) $user->id === (int) $instructorProfile->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InstructorProfile $instructorProfile): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && (int) $user->id === (int) $instructorProfile->user_id);
    }
}
