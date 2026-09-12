<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionOccurrence>
 */
class SubscriptionOccurrenceFactory extends Factory
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
            'subscription_id' => Subscription::factory(),
            'transaction_id' => null,
            'currency' => 'EUR',
            'amount' => '9.9900',
            'account_amount' => null,
            'due_date' => now()->toDateString(),
            'paid_at' => null,
            'notes' => null,
        ];
    }

    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn (): array => [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'currency' => $subscription->currency,
        ]);
    }
}
