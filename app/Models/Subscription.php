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
 * @property array<int, int>|null $notification_reminders
 * @property bool|null $notification_overdue
 * @property int|null $notification_overdue_frequency
 * @property array<string, array{days: array<int, int>, overdue_at: string|null}>|null $notification_sent
 * @property CarbonInterface $next_due_date
 */
#[Fillable(['account_id', 'category_id', 'payment_method_id', 'name', 'currency', 'amount', 'period_count', 'period_unit', 'next_due_date', 'auto_post', 'active', 'notification_channel_overrides', 'notification_reminders', 'notification_overdue', 'notification_overdue_frequency'])]
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
            'notification_reminders' => 'array',
            'notification_overdue' => 'boolean',
            'notification_overdue_frequency' => 'integer',
            'notification_sent' => 'array',
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

    /**
     * Days before the due date this subscription wants reminders, using the
     * subscription override or the owner's default schedule.
     *
     * @return array<int, int>
     */
    public function reminderDays(): array
    {
        return $this->notification_reminders !== null
            ? array_values(array_filter(array_map('intval', $this->notification_reminders)))
            : $this->user->notificationReminders();
    }

    public function overdueEnabled(): bool
    {
        return $this->notification_overdue ?? $this->user->notificationOverdue();
    }

    public function overdueFrequency(): int
    {
        return $this->notification_overdue_frequency ?? $this->user->notificationOverdueFrequency();
    }

    /**
     * Tracking for a single due date: which reminder days were already sent
     * and when the last overdue nudge went out.
     *
     * @return array{days: array<int, int>, overdue_at: string|null}
     */
    public function notificationStateFor(string $dueDate): array
    {
        $state = $this->notification_sent ?? [];

        return [
            'days' => $state[$dueDate]['days'] ?? [],
            'overdue_at' => $state[$dueDate]['overdue_at'] ?? null,
        ];
    }

    /**
     * @param  array<int, int>  $days
     */
    public function markRemindersSent(string $dueDate, array $days): void
    {
        $state = $this->notification_sent ?? [];
        $state[$dueDate]['days'] = array_values(array_unique(array_merge($state[$dueDate]['days'] ?? [], array_map('intval', $days))));
        $this->forceFill(['notification_sent' => $state])->save();
    }

    public function markOverdueSent(string $dueDate, CarbonInterface $date): void
    {
        $state = $this->notification_sent ?? [];
        $state[$dueDate]['overdue_at'] = $date->toDateString();
        $this->forceFill(['notification_sent' => $state])->save();
    }

    /**
     * Mark all reminders and the overdue slot as sent for a due date, used
     * when an occurrence is posted so no notifications fire for it anymore.
     *
     * @param  array<int, int>  $days
     */
    public function markNotificationFullySent(CarbonInterface $date, array $days): void
    {
        $state = $this->notification_sent ?? [];
        $state[$date->toDateString()] = [
            'days' => array_values(array_unique(array_map('intval', $days))),
            'overdue_at' => $date->toDateString(),
        ];
        $this->forceFill(['notification_sent' => $state])->save();
    }
}
