<?php

namespace App\Console\Commands;

use App\Concerns\ResolvesUsers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('user:unlock {user : The user ID or email address}')]
#[Description('Unlock a previously locked user account')]
class UnlockUser extends Command
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

        if (! $user->isLocked()) {
            $this->components->info("User [{$user->email}] is not locked.");

            return Command::SUCCESS;
        }

        $user->unlock();

        $this->components->info("User [{$user->email}] unlocked.");

        return Command::SUCCESS;
    }
}
