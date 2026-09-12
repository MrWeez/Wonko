<?php

namespace App\Models;

use Database\Factories\BankFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Bank extends UserOwnedModel
{
    /** @use HasFactory<BankFactory> */
    use HasFactory;

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
