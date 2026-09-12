<?php

namespace App\Console\Commands;

use App\Concerns\ResolvesUsers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('user:reset-password {user : The user ID or email address} {--password= : New password (generated when omitted)}')]
#[Description('Force reset a user\'s password')]
class ResetUserPassword extends Command
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

        $password = $this->option('password') ?: Str::password(24);

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        $this->components->info("Password reset for [{$user->email}].");

        if (! $this->option('password')) {
            $this->components->warn("Generated password: {$password}");
        }

        return Command::SUCCESS;
    }
}
