<?php

namespace App\Models;

use App\TransactionType;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property TransactionType $type
 * @property string $amount
 * @property string|null $account_amount
 */
#[Fillable(['account_id', 'related_transaction_id', 'type', 'description', 'currency', 'amount', 'account_amount', 'transaction_date'])]
class Transaction extends UserOwnedModel
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:4',
            'account_amount' => 'decimal:4',
            'transaction_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'related_transaction_id');
    }

    /**
     * @return HasOne<Transaction, $this>
     */
    public function inverseRelatedTransaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'related_transaction_id');
    }

    public function amountOnAccount(): string
    {
        return (string) ($this->account_amount ?? $this->amount);
    }

    public function signedAmountOnAccount(): string
    {
        return $this->type === TransactionType::Income
            ? $this->amountOnAccount()
            : '-'.$this->amountOnAccount();
    }
}
