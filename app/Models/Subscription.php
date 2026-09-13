<?php

namespace App\Models;

use App\SubscriptionPeriodUnit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SubscriptionPeriodUnit $period_unit
 * @property int $period_count
 * @property array<string, bool>|null $notification_channel_overrides
 * @property CarbonImmutable|null $notification_sent_for_due_date
 * @property CarbonInterface $next_due_date
 */
#[Fillable(['account_id', 'category_id', 'payment_method_id', 'name', 'currency', 'amount', 'period_count', 'period_unit', 'next_due_date', 'auto_post', 'active', 'notification_channel_overrides'])]
class Subscription extends UserOwnedModel
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'period_count' => 'integer',
            'period_unit' => SubscriptionPeriodUnit::class,
            'next_due_date' => 'date',
            'auto_post' => 'boolean',
            'active' => 'boolean',
            'notification_channel_overrides' => 'array',
            'notification_sent_for_due_date' => 'date',
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
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<SubscriptionOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(SubscriptionOccurrence::class);
    }

    public function nextDueDateAfter(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);

        return match ($this->period_unit) {
            SubscriptionPeriodUnit::Day => $date->addDays($this->period_count),
            SubscriptionPeriodUnit::Week => $date->addWeeks($this->period_count),
            SubscriptionPeriodUnit::Month => $date->addMonths($this->period_count),
            SubscriptionPeriodUnit::Year => $date->addYears($this->period_count),
        };
    }

    public function notificationSentFor(CarbonInterface $date): bool
    {
        return $this->notification_sent_for_due_date?->isSameDay($date) ?? false;
    }

    public function markNotificationSent(CarbonInterface $date): void
    {
        $this->forceFill(['notification_sent_for_due_date' => $date])->save();
    }
}
