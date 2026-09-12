<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('user:create {--name= : Display name} {--email= : Email address} {--password= : Password (generated when omitted)} {--verify : Mark the email as verified}')]
#[Description('Create a new user')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: Str::password(24);

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return Command::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => $this->option('verify') ? now() : null,
        ]);

        $this->components->info("User created [{$user->email}] with ID [{$user->id}].");

        if (! $this->option('password')) {
            $this->components->warn("Generated password: {$password}");
        }

        return Command::SUCCESS;
    }
}
