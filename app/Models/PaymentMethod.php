<?php

namespace App\Models;

use App\PaymentMethodType;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PaymentMethodType $type
 * @property bool $auto_split_balance
 */
#[Fillable(['name', 'type', 'primary_account_id', 'secondary_account_id', 'auto_split_balance', 'description'])]
class PaymentMethod extends UserOwnedModel
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'auto_split_balance' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function primaryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'primary_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function secondaryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'secondary_account_id');
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
     * Calculate automatic split amounts based on primary account live balance.
     *
     * @return array{primary_amount: string, secondary_amount: string}
     */
    public function calculateSplit(string|float $totalAmount): array
    {
        $amountFloat = (float) $totalAmount;

        if ($amountFloat <= 0 || ! $this->secondary_account_id || $this->type !== PaymentMethodType::Composite) {
            return [
                'primary_amount' => sprintf('%.4f', $amountFloat),
                'secondary_amount' => '0.0000',
            ];
        }

        $primaryBalance = max(0.0, (float) ($this->primaryAccount?->balance() ?? 0));

        if ($primaryBalance >= $amountFloat) {
            return [
                'primary_amount' => sprintf('%.4f', $amountFloat),
                'secondary_amount' => '0.0000',
            ];
        }

        if ($primaryBalance > 0.0) {
            return [
                'primary_amount' => sprintf('%.4f', $primaryBalance),
                'secondary_amount' => sprintf('%.4f', $amountFloat - $primaryBalance),
            ];
        }

        return [
            'primary_amount' => '0.0000',
            'secondary_amount' => sprintf('%.4f', $amountFloat),
        ];
    }
}
