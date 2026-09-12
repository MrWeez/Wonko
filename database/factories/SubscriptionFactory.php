<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use App\SubscriptionPeriodUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            'category_id' => null,
            'name' => fake()->unique()->company().' subscription',
            'currency' => 'EUR',
            'amount' => '9.9900',
            'period_count' => 1,
            'period_unit' => SubscriptionPeriodUnit::Month,
            'next_due_date' => now()->addMonth()->toDateString(),
            'auto_post' => false,
            'active' => true,
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
}
