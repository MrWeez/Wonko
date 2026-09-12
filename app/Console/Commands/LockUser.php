<?php

namespace App\Console\Commands;

use App\Concerns\ResolvesUsers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('user:lock {user : The user ID or email address}')]
#[Description('Lock a user account, blocking them from logging in')]
class LockUser extends Command
{
    use ResolvesUsers;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $user = $this->resolveUser($this->argument('user'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($user->isLocked()) {
            $this->components->info("User [{$user->email}] is already locked.");

            return Command::SUCCESS;
        }

        $user->lock();

        $this->components->info("User [{$user->email}] locked.");

        return Command::SUCCESS;
    }
}
