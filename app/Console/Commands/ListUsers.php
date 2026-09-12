<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:list')]
#[Description('List all users')]
class ListUsers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $users = User::query()
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'email_verified_at', 'locked_at', 'created_at']);

        if ($users->isEmpty()) {
            $this->components->warn('No users found.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Verified', 'Locked', 'Created'],
            $users->map(fn (User $user): array => [
                $user->id,
                $user->name,
                $user->email,
                $user->email_verified_at ? 'yes' : 'no',
                $user->locked_at ? 'yes' : 'no',
                $user->created_at->toDateTimeString(),
            ]),
        );

        return Command::SUCCESS;
    }
}
