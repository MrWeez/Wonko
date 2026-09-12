<?php

namespace App\Concerns;

use App\Models\User;
use InvalidArgumentException;

trait ResolvesUsers
{
    /**
     * Resolve a user from the given ID or email address.
     */
    private function resolveUser(string $value): User
    {
        $user = is_numeric($value)
            ? User::query()->find($value)
            : User::query()->where('email', $value)->first();

        if (! $user) {
            throw new InvalidArgumentException("User [{$value}] not found.");
        }

        return $user;
    }
}
