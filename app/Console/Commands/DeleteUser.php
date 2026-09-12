<?php

namespace App\Console\Commands;

use App\Concerns\ResolvesUsers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('user:delete {user : The user ID or email address} {--anonymize : Keep financial data but strip the user\'s identity}')]
#[Description('Delete a user. By default the user and all their data are deleted; use --anonymize to keep the data')]
class DeleteUser extends Command
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

        if ($this->option('anonymize')) {
            $user->anonymizePersonalData();

            $this->components->info("User [{$user->email}] anonymized. Their data was kept.");

            return Command::SUCCESS;
        }

        $email = $user->email;
        $user->delete();

        $this->components->info("User [{$email}] and all their data were deleted.");

        return Command::SUCCESS;
    }
}
