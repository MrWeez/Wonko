<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'account_id' => Account::factory(),
            'related_transaction_id' => null,
            'type' => TransactionType::Expense,
            'description' => fake()->sentence(3),
            'currency' => 'EUR',
            'amount' => '10.0000',
            'account_amount' => null,
            'transaction_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn (): array => [
            'user_id' => $account->user_id,
            'account_id' => $account->id,
            'currency' => $account->currency,
        ]);
    }

    public function income(): static
    {
        return $this->state(['type' => TransactionType::Income]);
    }

    public function expense(): static
    {
        return $this->state(['type' => TransactionType::Expense]);
    }
}
