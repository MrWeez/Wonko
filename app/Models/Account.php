<?php

namespace App\Models;

use App\AccountType;
use App\TransactionType;
use App\TransferDirection;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'currency', 'type', 'bank_id', 'is_primary'])]
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
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Mark this account as the user's primary account, demoting any other.
     */
    public function markAsPrimary(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('user_id', $this->user_id)
                ->where('id', '!=', $this->id)
                ->update(['is_primary' => false]);

            $this->forceFill(['is_primary' => true])->save();
        });
    }

    /**
     * Order: primary account first, then by name.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeDefaultOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('is_primary DESC')
            ->orderBy('name');
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
     * @return HasMany<PaymentMethod, $this>
     */
    public function primaryPaymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'primary_account_id');
    }

    /**
     * @return HasMany<PaymentMethod, $this>
     */
    public function secondaryPaymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'secondary_account_id');
    }

    /**
     * Return the live balance derived from income and expense transactions.
     */
    public function balance(): string
    {
        return (string) $this->transactions()
            ->withoutGlobalScope('owned-by-user')
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
