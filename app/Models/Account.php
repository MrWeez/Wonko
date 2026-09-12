<?php

namespace App\Models;

use App\AccountType;
use App\TransactionType;
use App\TransferDirection;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'currency', 'type', 'bank_id'])]
class Account extends UserOwnedModel
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
        ];
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Return the live balance derived from income and expense transactions.
     */
    public function balance(): string
    {
        return (string) $this->transactions()
            ->whereIn('type', [
                TransactionType::Expense->value,
                TransactionType::Income->value,
                TransactionType::Transfer->value,
            ])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? OR (type = ? AND transfer_direction = ?) THEN COALESCE(account_amount, amount) ELSE -COALESCE(account_amount, amount) END), 0) AS balance',
                [
                    TransactionType::Income->value,
                    TransactionType::Transfer->value,
                    TransferDirection::Destination->value,
                ],
            )
            ->value('balance');
    }
}
