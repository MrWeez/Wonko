<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\User;
use App\PaymentMethodType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
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
            'name' => fake()->words(2, true),
            'type' => PaymentMethodType::Composite,
            'primary_account_id' => Account::factory(),
            'secondary_account_id' => Account::factory(),
            'auto_split_balance' => true,
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function composite(): static
    {
        return $this->state(fn (): array => [
            'type' => PaymentMethodType::Composite,
        ]);
    }

    public function single(): static
    {
        return $this->state(fn (): array => [
            'type' => PaymentMethodType::Single,
            'secondary_account_id' => null,
            'auto_split_balance' => false,
        ]);
    }
}
