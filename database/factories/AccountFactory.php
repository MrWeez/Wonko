<?php

namespace Database\Factories;

use App\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'currency' => fake()->randomElement(['EUR', 'USD', 'GBP']),
            'type' => fake()->randomElement(AccountType::cases()),
            'bank_id' => null,
        ];
    }

    public function forBank(Bank $bank): static
    {
        return $this->state(fn (): array => [
            'user_id' => $bank->user_id,
            'bank_id' => $bank->id,
        ]);
    }
}
